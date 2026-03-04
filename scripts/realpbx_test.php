<?php

declare(strict_types=1);

use Apn\AmiClient\Correlation\ActionIdGenerator;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Exceptions\ProtocolException;
use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Protocol\Ping;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Strategies\FollowsResponseStrategy;
use Dotenv\Dotenv;
use Tests\RealPbx\RealPbxConfig;
use Tests\RealPbx\RealPbxRunner;
use Tests\RealPbx\Redactor;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../tests/RealPbx/Redactor.php';
require_once __DIR__ . '/../tests/RealPbx/RealPbxConfig.php';
require_once __DIR__ . '/../tests/RealPbx/TapTransport.php';
require_once __DIR__ . '/../tests/RealPbx/RealPbxRunner.php';

$root = dirname(__DIR__);
$runDir = $root . '/docs/real-pbx-runs';
if (!is_dir($runDir)) {
    mkdir($runDir, 0775, true);
}

if (class_exists(Dotenv::class) && is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$ts = date('Ymd_His');

$secretsPath = $root . '/tests/.secrets/ami.local.php';
$secrets = [];
if (is_file($secretsPath)) {
    $loaded = require $secretsPath;
    if (is_array($loaded)) {
        $secrets = $loaded;
    }
}

$cfgInput = [
    'server_key' => envValue('AMI_SERVER_KEY') ?: ($secrets['server_key'] ?? 'pbx01'),
    'host' => envValue('AMI_HOST') ?: ($secrets['host'] ?? null),
    'port' => (int) (envValue('AMI_PORT') ?: ($secrets['port'] ?? 5038)),
    'username' => envValue('AMI_USERNAME') ?: ($secrets['username'] ?? null),
    'secret' => envValue('AMI_SECRET') ?: ($secrets['secret'] ?? null),
    'tls' => filter_var(envValue('AMI_TLS') ?: ($secrets['tls'] ?? false), FILTER_VALIDATE_BOOL),
    'connect_timeout_ms' => (int) (envValue('AMI_CONNECT_TIMEOUT_MS') ?: ($secrets['connect_timeout_ms'] ?? 2000)),
    'auth_timeout_ms' => (int) (envValue('AMI_AUTH_TIMEOUT_MS') ?: ($secrets['auth_timeout_ms'] ?? 2000)),
];

$config = RealPbxConfig::fromArray($cfgInput);
$redactor = new Redactor([$config->secret ?? '']);

$gate = buildEnvGate($root, $config);
$envReportPath = $runDir . '/' . $ts . '_env-gate.md';
file_put_contents($envReportPath, renderEnvGateReport($gate));

if ($gate['decision'] !== 'PASS') {
    fwrite(STDOUT, "Real PBX integration is skipped until credentials are configured.\n");
    exit(0);
}

$manifestPath = $root . '/tests/RealPbx/actions.manifest.php';
$manifest = require $manifestPath;
if (!is_array($manifest)) {
    fwrite(STDERR, "Manifest is invalid: $manifestPath\n");
    exit(1);
}

$manifestReportPath = $runDir . '/' . $ts . '_action-manifest.md';
file_put_contents($manifestReportPath, renderManifestReport($manifest));

$runner = new RealPbxRunner($config, $redactor, $manifest);
$suite = $runner->run();
$suiteReportPath = $runDir . '/' . $ts . '_real-pbx-action-suite.md';
file_put_contents($suiteReportPath, renderSuiteReport($suite, $redactor));

$contract = runProtocolContractChecks($root);
$contractReportPath = $runDir . '/' . $ts . '_protocol-contract-checks.md';
file_put_contents($contractReportPath, renderContractReport($contract));

fwrite(STDOUT, "Wrote reports:\n");
fwrite(STDOUT, "- $envReportPath\n");
fwrite(STDOUT, "- $manifestReportPath\n");
fwrite(STDOUT, "- $suiteReportPath\n");
fwrite(STDOUT, "- $contractReportPath\n");

/**
 * @return array<string, mixed>
 */
function buildEnvGate(string $root, RealPbxConfig $config): array
{
    $requiredVars = [
        'AMI_HOST' => $config->host,
        'AMI_PORT' => (string) $config->port,
        'AMI_USERNAME' => $config->username,
        'AMI_SECRET' => $config->secret,
        'AMI_TLS' => $config->tls ? 'true' : 'false',
        'AMI_SERVER_KEY' => $config->serverKey,
        'AMI_CONNECT_TIMEOUT_MS' => (string) $config->connectTimeoutMs,
        'AMI_AUTH_TIMEOUT_MS' => (string) $config->authTimeoutMs,
    ];

    $checks = [
        ['name' => 'src/Protocol exists', 'ok' => is_dir($root . '/src/Protocol')],
        ['name' => 'composer.json exists', 'ok' => is_file($root . '/composer.json')],
        ['name' => 'PHP 8.4+', 'ok' => version_compare(PHP_VERSION, '8.4.0', '>=')],
        ['name' => 'Composer available', 'ok' => trim((string) shell_exec('composer -V 2>/dev/null')) !== ''],
    ];

    foreach ($requiredVars as $key => $value) {
        $checks[] = ['name' => "$key set", 'ok' => $value !== null && $value !== ''];
    }

    $decision = 'PASS';
    foreach ($checks as $check) {
        if (!$check['ok']) {
            $decision = 'FAIL';
            break;
        }
    }

    return [
        'timestamp' => date('c'),
        'checks' => $checks,
        'decision' => $decision,
    ];
}

function envValue(string $key): ?string
{
    $value = getenv($key);
    if (is_string($value) && $value !== '') {
        return $value;
    }

    if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }

    return null;
}

/**
 * @param array<string, mixed> $gate
 */
function renderEnvGateReport(array $gate): string
{
    $lines = [];
    $lines[] = '# Real PBX Validation Pipeline - Environment Gate';
    $lines[] = '';
    $lines[] = '- Timestamp: ' . $gate['timestamp'];
    $lines[] = '- Phase: 0 (Environment Gate)';
    $lines[] = '';
    $lines[] = '| Check | Result |';
    $lines[] = '|---|---|';

    foreach ($gate['checks'] as $check) {
        $lines[] = sprintf('| %s | %s |', $check['name'], $check['ok'] ? 'PASS' : 'FAIL');
    }

    $lines[] = '';
    $lines[] = '## Decision';
    $lines[] = '';
    $lines[] = $gate['decision'] === 'PASS'
        ? 'Environment gate passed.'
        : 'Real PBX integration is skipped until credentials are configured.';

    return implode("\n", $lines) . "\n";
}

/**
 * @param array<int, array<string, mixed>> $manifest
 */
function renderManifestReport(array $manifest): string
{
    $lines = [];
    $lines[] = '# Real PBX Action Manifest';
    $lines[] = '';
    $lines[] = '| Action | Class | Kind | Enabled | Required Params | Completion |';
    $lines[] = '|---|---|---|---|---|---|';

    foreach ($manifest as $entry) {
        $required = implode(', ', $entry['required_params'] ?? []);
        $completion = (string) (($entry['completion']['timeout_ms'] ?? 0) . 'ms');
        if (!empty($entry['completion']['terminal_events'])) {
            $completion .= ' / ' . implode(',', $entry['completion']['terminal_events']);
        }
        $lines[] = sprintf(
            '| %s | `%s` | %s | %s | %s | %s |',
            $entry['name'] ?? 'unknown',
            $entry['class'] ?? 'unknown',
            $entry['kind'] ?? 'single',
            (($entry['enabled'] ?? true) ? 'yes' : 'no'),
            $required === '' ? '-' : $required,
            $completion
        );
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param array<string, mixed> $suite
 */
function renderSuiteReport(array $suite, Redactor $redactor): string
{
    $lines = [];
    $lines[] = '# Real PBX Action Suite';
    $lines[] = '';
    $lines[] = '- Infra status: ' . ($suite['infra_status'] ?? 'UNKNOWN');
    $lines[] = '- Connect time (ms): ' . (string) ($suite['connect_ms'] ?? 'n/a');
    $lines[] = '- Auth time (ms): ' . (string) ($suite['auth_ms'] ?? 'n/a');
    $lines[] = '- Banner received: ' . ((bool) ($suite['banner_seen'] ?? false) ? 'yes' : 'no');
    if (isset($suite['infra_error']['message'])) {
        $lines[] = '- Infra error: ' . $redactor->redactString((string) $suite['infra_error']['message']);
    }
    $lines[] = '';
    $lines[] = '## Action Results';
    $lines[] = '';
    $lines[] = '| Action | Status | Latency (ms) | Response | Details |';
    $lines[] = '|---|---|---|---|---|';

    foreach (($suite['actions'] ?? []) as $action) {
        $lines[] = sprintf(
            '| %s | %s | %s | %s | %s |',
            $action['name'] ?? 'unknown',
            $action['status'] ?? 'UNKNOWN',
            isset($action['latency_ms']) ? (string) $action['latency_ms'] : '-',
            $action['response'] ?? '-',
            $action['reason'] ?? ('events=' . (string) ($action['events'] ?? 0) . '; terminal=' . ((bool) ($action['terminal_observed'] ?? false) ? 'yes' : 'no')),
        );
    }

    $lines[] = '';
    $lines[] = '## Sanitized Raw Frame Header Sample';
    $lines[] = '';

    foreach (($suite['raw_header_sample'] ?? []) as $line) {
        $lines[] = '- ' . $redactor->redactString((string) $line);
    }

    if (($suite['raw_header_sample'] ?? []) === []) {
        $lines[] = '- (none captured)';
    }

    $lines[] = '';
    $lines[] = '## Parsed DTO Sample';
    $lines[] = '';

    $printed = 0;
    foreach (($suite['actions'] ?? []) as $action) {
        if (!isset($action['normalized_sample'])) {
            continue;
        }
        $printed++;
        $lines[] = '### ' . ($action['name'] ?? 'unknown');
        $lines[] = '```json';
        $lines[] = json_encode($action['normalized_sample'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
        $lines[] = '```';

        if ($printed >= 3) {
            break;
        }
    }

    if ($printed === 0) {
        $lines[] = '- (none captured)';
    }

    $lines[] = '';
    $lines[] = '## Sanitized Failure Details';
    $lines[] = '';

    $hadFailure = false;
    foreach (($suite['actions'] ?? []) as $action) {
        if (($action['status'] ?? '') !== 'FAIL') {
            continue;
        }
        $hadFailure = true;
        $lines[] = '- ' . ($action['name'] ?? 'unknown') . ': ' . ($action['reason'] ?? 'unknown failure');
        if (isset($action['exception_class'])) {
            $lines[] = '  Exception: `' . $action['exception_class'] . '`';
        }
    }

    if (!$hadFailure) {
        $lines[] = '- No action failures recorded.';
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @return array<string, mixed>
 */
function runProtocolContractChecks(string $root): array
{
    $checks = [];

    $generator = new ActionIdGenerator('pbx01', 'runner01', 64);
    $ids = [];
    $actionIdOk = true;
    for ($i = 0; $i < 5; $i++) {
        $id = $generator->next();
        $ids[] = $id;
        if (!preg_match('/^[^:]+:[^:]+:\\d+$/', $id) || strlen($id) > 64) {
            $actionIdOk = false;
        }
    }
    $checks[] = ['name' => 'ActionID format contract', 'ok' => $actionIdOk, 'details' => implode(', ', $ids)];

    $regA = new CorrelationRegistry();
    $regB = new CorrelationRegistry();
    $regA->register((new Ping())->withActionId('srvA:inst:1'));
    $regB->register((new Ping())->withActionId('srvB:inst:1'));
    $regA->handleResponse(new Response(['response' => 'Success', 'actionid' => 'srvB:inst:1']));
    $correlationOk = ($regA->count() === 1 && $regB->count() === 1);
    $checks[] = ['name' => 'Response correlation isolation', 'ok' => $correlationOk, 'details' => 'cross-server action id ignored'];

    $parserOk = false;
    try {
        $parser = new Parser(maxFrameSize: 65536);
        $oversize = "Response: Success\r\nActionID: x\r\nPayload: " . str_repeat('A', 70000) . "\r\n\r\n";
        $parser->push($oversize);
        $parser->next();
    } catch (ProtocolException) {
        $parserOk = true;
    }
    $checks[] = ['name' => 'Parser frame-size cap', 'ok' => $parserOk, 'details' => 'oversize frame rejected'];

    $followsOk = false;
    try {
        $strategy = new FollowsResponseStrategy(maxOutputSize: 32);
        $strategy->onResponse(new Response(['response' => 'Follows', 'output' => str_repeat('X', 64)]));
    } catch (ProtocolException) {
        $followsOk = true;
    }
    $checks[] = ['name' => 'Follows output cap', 'ok' => $followsOk, 'details' => 'oversize follows output rejected'];

    $blockingCalls = [];
    foreach (['src/Core', 'src/Transport', 'src/Protocol'] as $scanPath) {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $scanPath));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $content = file_get_contents($file->getPathname()) ?: '';
            if (preg_match('/\b(usleep|sleep|time_nanosleep)\s*\(/', $content)) {
                $blockingCalls[] = str_replace($root . '/', '', $file->getPathname());
            }
        }
    }

    $checks[] = [
        'name' => 'Non-blocking guarantee (no sleep calls in src)',
        'ok' => $blockingCalls === [],
        'details' => $blockingCalls === [] ? 'none found' : implode(', ', $blockingCalls),
    ];

    return [
        'timestamp' => date('c'),
        'checks' => $checks,
    ];
}

/**
 * @param array<string, mixed> $contract
 */
function renderContractReport(array $contract): string
{
    $lines = [];
    $lines[] = '# Protocol Contract Checks';
    $lines[] = '';
    $lines[] = '- Timestamp: ' . ($contract['timestamp'] ?? date('c'));
    $lines[] = '';
    $lines[] = '| Check | Result | Details |';
    $lines[] = '|---|---|---|';

    foreach ($contract['checks'] as $check) {
        $lines[] = sprintf('| %s | %s | %s |', $check['name'], $check['ok'] ? 'PASS' : 'FAIL', $check['details']);
    }

    return implode("\n", $lines) . "\n";
}
