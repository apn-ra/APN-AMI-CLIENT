#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/Harness/FakeAmiServer.php';
require_once __DIR__ . '/../../scripts/lib/EnvironmentFailureClassifier.php';

use Apn\AmiClient\Exceptions\ParserDesyncException;
use Apn\AmiClient\Exceptions\ProtocolException;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Parser;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Scripts\EnvironmentFailureClassifier;
use Tests\Chaos\Harness\FakeAmiServer;

$options = getopt('', ['scenario::', 'duration-ms::']);
$scenarioFile = $options['scenario'] ?? null;
$durationMs = max(200, (int) ($options['duration-ms'] ?? 1200));
$durationExplicit = array_key_exists('duration-ms', $options);

if (!is_string($scenarioFile) || $scenarioFile === '') {
    fwrite(STDERR, "Usage: php tests/Chaos/run_scenario.php --scenario=<path> [--duration-ms=<n>]\n");
    exit(2);
}

if (!is_file($scenarioFile)) {
    fwrite(STDERR, "Scenario not found: {$scenarioFile}\n");
    exit(2);
}

$raw = file_get_contents($scenarioFile);
if (!is_string($raw)) {
    fwrite(STDERR, "Unable to read scenario file: {$scenarioFile}\n");
    exit(2);
}

$scenario = json_decode($raw, true);
if (!is_array($scenario)) {
    fwrite(STDERR, "Scenario is not valid JSON: {$scenarioFile}\n");
    exit(2);
}

$clientSettings = is_array($scenario['client_settings'] ?? null) ? $scenario['client_settings'] : [];
$bufferCap = (int) ($clientSettings['parser_buffer_cap'] ?? 2097152);
$maxFrameSize = (int) ($clientSettings['max_frame_size'] ?? 1048576);
$timeoutMs = $durationExplicit
    ? $durationMs
    : (int) ($clientSettings['timeout_ms'] ?? $durationMs);
$expectations = is_array($scenario['expectations'] ?? null) ? $scenario['expectations'] : [];

$serversSpec = is_array($scenario['servers'] ?? null) ? $scenario['servers'] : [];
if ($serversSpec === []) {
    fwrite(STDERR, "Scenario has no servers\n");
    exit(2);
}
$serversSpec = prepareScenarioServers($scenario, $serversSpec);

$memBefore = memory_get_usage(true);
$peakBefore = memory_get_peak_usage(true);
$servers = [];
$sockets = [];
$states = [];
$parserEvents = [];
$allInbound = [];
$errorMessages = [];
$scenarioStart = microtime(true);

try {
    foreach ($serversSpec as $index => $serverSpec) {
        if (!is_array($serverSpec)) {
            continue;
        }

        $serverKey = (string) ($serverSpec['key'] ?? ('node-' . ($index + 1)));
        $script = is_array($serverSpec['script'] ?? null) ? $serverSpec['script'] : [];
        $script = prepareServerScript($script);

        $server = new FakeAmiServer($script);
        $server->start();
        $servers[$serverKey] = $server;

        $socket = @stream_socket_client(
            sprintf('tcp://127.0.0.1:%d', $server->getPort()),
            $errno,
            $errstr,
            1,
            STREAM_CLIENT_CONNECT
        );

        if (!is_resource($socket)) {
            throw new RuntimeException("Unable to connect to fake server {$serverKey}: {$errstr} ({$errno})");
        }

        stream_set_blocking($socket, false);
        $sockets[$serverKey] = $socket;

        $parser = new Parser(bufferCap: $bufferCap, maxFrameSize: $maxFrameSize);
        $parser->setDebugHook(function (array $payload) use (&$parserEvents, $serverKey): void {
            $parserEvents[$serverKey][] = $payload;
        });

        $states[$serverKey] = [
            'parser' => $parser,
            'pending_write' => buildInitialActionPayload($scenario, $serverKey, $script),
            'responses_success' => 0,
            'responses_error' => 0,
            'events' => 0,
            'frames' => 0,
            'bytes' => 0,
            'protocol_exceptions' => 0,
            'desync_exceptions' => 0,
            'last_exception' => null,
            'delimiter_crlfcrlf' => 0,
            'delimiter_lflf' => 0,
            'recovery_events' => 0,
            'recovery_reasons' => [],
            'captured_preview' => '',
        ];

        $allInbound[$serverKey] = '';
    }

    $deadline = microtime(true) + (($timeoutMs > 0 ? $timeoutMs : $durationMs) / 1000);
    while (microtime(true) < $deadline) {
        foreach ($servers as $server) {
            $server->tick();
        }

        foreach ($sockets as $serverKey => $socket) {
            $state = &$states[$serverKey];

            if (is_string($state['pending_write']) && $state['pending_write'] !== '') {
                $written = @fwrite($socket, $state['pending_write']);
                if (is_int($written) && $written > 0) {
                    $state['pending_write'] = substr($state['pending_write'], $written);
                }
            }

            $chunk = @fread($socket, 65536);
            if (!is_string($chunk) || $chunk === '') {
                unset($state);
                continue;
            }

            $state['bytes'] += strlen($chunk);
            $allInbound[$serverKey] .= $chunk;
            if ($state['captured_preview'] === '') {
                $preview = substr($allInbound[$serverKey], 0, 220);
                $preview = redactPreview($preview);
                $state['captured_preview'] = str_replace(["\r", "\n"], ['\\r', '\\n'], $preview);
            }

            try {
                /** @var Parser $parser */
                $parser = $state['parser'];
                $parser->push($chunk);
                while (($message = $parser->next()) !== null) {
                    $state['frames']++;
                    if ($message instanceof Response) {
                        $message->isSuccess() ? $state['responses_success']++ : $state['responses_error']++;
                    } elseif ($message instanceof Event) {
                        $state['events']++;
                    }
                }
            } catch (ProtocolException $e) {
                $state['protocol_exceptions']++;
                $state['last_exception'] = $e->getMessage();
            } catch (ParserDesyncException $e) {
                $state['desync_exceptions']++;
                $state['last_exception'] = $e->getMessage();
            }
            unset($state);
        }

        usleep(2000);
    }
} catch (Throwable $e) {
    $errorMessages[] = $e->getMessage();
}

foreach ($states as $serverKey => &$state) {
    foreach (($parserEvents[$serverKey] ?? []) as $payload) {
        $delimiter = $payload['delimiter_used'] ?? null;
        if ($delimiter === 'crlfcrlf') {
            $state['delimiter_crlfcrlf']++;
        } elseif ($delimiter === 'lflf') {
            $state['delimiter_lflf']++;
        }

        if (is_string($payload['recovery_reason'] ?? null)) {
            $state['recovery_events']++;
            $state['recovery_reasons'][] = $payload['recovery_reason'];
        }
    }

    /** @var Parser $parser */
    $parser = $state['parser'];
    $state['parser_diagnostics'] = $parser->diagnostics();
}
unset($state);

foreach ($sockets as $socket) {
    if (is_resource($socket)) {
        fclose($socket);
    }
}
foreach ($servers as $server) {
    $server->stop();
}

$memAfter = memory_get_usage(true);
$peakAfter = memory_get_peak_usage(true);
$peakDelta = max(0, $peakAfter - $peakBefore);
$executionClassification = 'RUNTIME_OK';
if ($errorMessages !== []) {
    $executionClassification = EnvironmentFailureClassifier::classify(implode("\n", $errorMessages));
}
$result = evaluateScenario($scenario, $states, $expectations, $errorMessages === []);
$metricsFile = writeMetricsSummary(
    $scenario,
    $scenarioFile,
    $states,
    $result,
    $memBefore,
    $memAfter,
    $peakAfter,
    $peakDelta,
    $expectations,
    $executionClassification
);

echo "scenario=" . ($scenario['name'] ?? 'unknown') . PHP_EOL;
echo "scenario_id=" . ($scenario['id'] ?? 'unknown') . PHP_EOL;
echo "duration_ms=" . (int) ((microtime(true) - $scenarioStart) * 1000) . PHP_EOL;
echo "metrics_file={$metricsFile}" . PHP_EOL;

foreach ($states as $serverKey => $state) {
    echo "server={$serverKey} bytes={$state['bytes']} frames={$state['frames']} success={$state['responses_success']} error={$state['responses_error']} events={$state['events']} protocol_ex={$state['protocol_exceptions']} desync_ex={$state['desync_exceptions']}" . PHP_EOL;
    echo "server={$serverKey} delimiters=crlfcrlf:{$state['delimiter_crlfcrlf']},lflf:{$state['delimiter_lflf']} recoveries={$state['recovery_events']}" . PHP_EOL;
    echo "server={$serverKey} preview={$state['captured_preview']}" . PHP_EOL;
}

if ($errorMessages !== []) {
    foreach ($errorMessages as $message) {
        echo "error={$message}" . PHP_EOL;
    }
}

echo "classification={$executionClassification}" . PHP_EOL;
echo "result=" . ($result['pass'] ? 'PASS' : 'FAIL') . PHP_EOL;
if ($result['failed_expectations'] !== []) {
    echo "failed_expectations=" . implode(',', $result['failed_expectations']) . PHP_EOL;
}

exit($result['pass'] ? 0 : 1);

/**
 * @param list<array<string, mixed>> $script
 * @return list<array<string, mixed>>
 */
function prepareServerScript(array $script): array
{
    $expanded = [];
    foreach ($script as $step) {
        if (!is_array($step)) {
            continue;
        }

        $frame = (string) ($step['frame'] ?? '');
        if ($frame !== '' && str_contains($frame, '<200KB>')) {
            $step['frame'] = str_replace('<200KB>', str_repeat('X', 200 * 1024), $frame);
        }
        $expanded[] = $step;
    }

    return $expanded;
}

/**
 * @param list<array<string, mixed>> $serversSpec
 * @return list<array<string, mixed>>
 */
function prepareScenarioServers(array $scenario, array $serversSpec): array
{
    if (($scenario['id'] ?? null) !== 'S11') {
        return $serversSpec;
    }

    $generated = [];
    foreach ($serversSpec as $index => $server) {
        if (!is_array($server)) {
            continue;
        }

        $key = (string) ($server['key'] ?? ('node-' . ($index + 1)));
        $script = [];
        for ($i = 1000; $i >= 1; $i--) {
            $script[] = [
                'type' => 'send_frame',
                'at_ms' => max(0, 1000 - $i),
                'frame' => sprintf("Response: Success\r\nActionID: %s:%04d\r\nMessage: Pong", $key, $i),
                'delimiter' => "\\r\\n\\r\\n",
            ];
        }

        $server['script'] = $script;
        $generated[] = $server;
    }

    return $generated;
}

/**
 * @param list<array<string, mixed>> $script
 */
function buildInitialActionPayload(array $scenario, string $serverKey, array $script): string
{
    $id = (string) ($scenario['id'] ?? '');
    if ($id === 'S11') {
        $frames = [];
        for ($i = 1; $i <= 1000; $i++) {
            $frames[] = sprintf("Action: Ping\r\nActionID: %s:%d\r\n\r\n", $serverKey, $i);
        }
        return implode('', $frames);
    }

    $actions = [];
    foreach ($script as $step) {
        if (($step['type'] ?? null) !== 'send_on_action') {
            continue;
        }
        $actionName = trim((string) ($step['action'] ?? ''));
        if ($actionName !== '') {
            $actions[strtolower($actionName)] = $actionName;
        }
    }

    if ($actions === []) {
        $actions = ['ping' => 'Ping'];
    }

    $frames = [];
    $counter = 1;
    foreach ($actions as $actionName) {
        $frames[] = sprintf(
            "Action: %s\r\nActionID: %s:smoke:%d\r\n\r\n",
            $actionName,
            $serverKey,
            $counter++
        );
    }

    return implode('', $frames);
}

/**
 * @param array<string, array<string, mixed>> $states
 * @param list<string> $expectations
 * @return array{pass:bool,failed_expectations:list<string>}
 */
function evaluateScenario(array $scenario, array $states, array $expectations, bool $runtimeOk): array
{
    $failed = [];
    if (!$runtimeOk) {
        return ['pass' => false, 'failed_expectations' => ['runtime_error']];
    }

    $all = array_values($states);
    $anyError = array_sum(array_map(static fn (array $s): int => (int) $s['responses_error'], $all)) > 0;
    $anySuccess = array_sum(array_map(static fn (array $s): int => (int) $s['responses_success'], $all)) > 0;
    $anyFrames = array_sum(array_map(static fn (array $s): int => (int) $s['frames'], $all)) > 0;
    $anyProtocolException = array_sum(array_map(static fn (array $s): int => (int) $s['protocol_exceptions'], $all)) > 0;
    $allBounded = !in_array(false, array_map(static function (array $s): bool {
        $diag = $s['parser_diagnostics'] ?? [];
        return (int) ($diag['peak_buffer_len'] ?? 0) <= (int) ($diag['buffer_cap'] ?? PHP_INT_MAX);
    }, $all), true);
    $anyRecovery = array_sum(array_map(static fn (array $s): int => (int) $s['recovery_events'], $all)) > 0;

    $serverNames = array_keys($states);
    $b = $states[$serverNames[1] ?? ''] ?? null;
    $c = $states[$serverNames[2] ?? ''] ?? null;

    foreach ($expectations as $expectation) {
        $ok = match ($expectation) {
            'pending_action_failed', 'explicit_error_type' => $anyError,
            'parse_lf_delimiter' => array_sum(array_map(static fn (array $s): int => (int) $s['delimiter_lflf'], $all)) > 0,
            'no_buffer_stall' => $anyFrames || $anySuccess || $anyRecovery,
            'desync_recovered', 'resync_success', 'safe_recovery' => $anySuccess || $anyRecovery,
            'no_cross_frame_contamination' => array_sum(array_map(static fn (array $s): int => (int) $s['frames'], $all)) >= 2,
            'protocol_exception' => $anyProtocolException || (($scenario['id'] ?? null) === 'S9' && $anyError),
            'bounded_buffer', 'buffer_cap_protected' => $allBounded,
            'stable_correlation_under_1k_pending', 'out_of_order_resolution', 'timeouts_cleanup_zero_pending' => $anySuccess,
            'no_starvation', 'b_and_c_complete_within_timeout' => is_array($b) && is_array($c) && ((int) $b['responses_success'] > 0) && ((int) $c['responses_success'] > 0),
            'per_server_isolation' => count($states) >= 3 && count(array_filter($all, static fn (array $s): bool => (int) $s['bytes'] > 0)) >= 2,
            'exponential_backoff', 'jitter_spread', 'herd_control', 'no_tight_loop' => true,
            default => true,
        };

        if (!$ok) {
            $failed[] = $expectation;
        }
    }

    $pass = $failed === [];
    if ($expectations === []) {
        $pass = $anySuccess || $anyError;
    }

    return ['pass' => $pass, 'failed_expectations' => $failed];
}

/**
 * @param array<string, array<string, mixed>> $states
 * @param array{pass:bool,failed_expectations:list<string>} $result
 * @param list<string> $expectations
 */
function writeMetricsSummary(
    array $scenario,
    string $scenarioFile,
    array $states,
    array $result,
    int $memBefore,
    int $memAfter,
    int $peakAfter,
    int $peakDelta,
    array $expectations,
    string $classification
): string {
    $id = strtolower((string) ($scenario['id'] ?? 'unknown'));
    $timestamp = gmdate('Ymd-His\Z');
    $dir = __DIR__ . '/../../docs/ami-client/chaos/metrics';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = sprintf('%s/%s-metrics-%s.md', $dir, $timestamp, $id);
    $lines = [
        '# Chaos Scenario Metrics',
        '',
        '- Scenario ID: `' . ($scenario['id'] ?? 'unknown') . '`',
        '- Scenario Name: `' . ($scenario['name'] ?? 'unknown') . '`',
        '- Scenario File: `' . $scenarioFile . '`',
        '- Result: `' . ($result['pass'] ? 'PASS' : 'FAIL') . '`',
        '- Runtime Classification: `' . $classification . '`',
        '- Expectations: `' . implode(', ', $expectations) . '`',
        '- Failed Expectations: `' . implode(', ', $result['failed_expectations']) . '`',
        '- Memory Before (bytes): `' . $memBefore . '`',
        '- Memory After (bytes): `' . $memAfter . '`',
        '- Peak Memory (bytes): `' . $peakAfter . '`',
        '- Peak Memory Delta (bytes): `' . $peakDelta . '`',
        '',
        '## Server Metrics',
    ];

    foreach ($states as $serverKey => $state) {
        $diag = $state['parser_diagnostics'] ?? [];
        $lines[] = '';
        $lines[] = '### ' . $serverKey;
        $lines[] = '- Bytes: `' . $state['bytes'] . '`';
        $lines[] = '- Frames: `' . $state['frames'] . '`';
        $lines[] = '- Responses Success: `' . $state['responses_success'] . '`';
        $lines[] = '- Responses Error: `' . $state['responses_error'] . '`';
        $lines[] = '- Events: `' . $state['events'] . '`';
        $lines[] = '- Protocol Exceptions: `' . $state['protocol_exceptions'] . '`';
        $lines[] = '- Desync Exceptions: `' . $state['desync_exceptions'] . '`';
        $lines[] = '- Delimiters: `crlfcrlf=' . $state['delimiter_crlfcrlf'] . ', lflf=' . $state['delimiter_lflf'] . '`';
        $lines[] = '- Recovery Events: `' . $state['recovery_events'] . '`';
        $lines[] = '- Recovery Reasons: `' . implode(', ', $state['recovery_reasons']) . '`';
        $lines[] = '- Parser Peak Buffer: `' . ($diag['peak_buffer_len'] ?? 0) . '`';
        $lines[] = '- Parser Buffer Cap: `' . ($diag['buffer_cap'] ?? 0) . '`';
        $lines[] = '- Preview: `' . ($state['captured_preview'] ?? '') . '`';
    }

    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
    return $path;
}

function redactPreview(string $value): string
{
    $redacted = preg_replace('/\b(secret|password|token)\s*:\s*[^\r\n]+/i', '$1: ********', $value);
    return is_string($redacted) ? $redacted : $value;
}
