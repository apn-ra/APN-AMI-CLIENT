# apntalk/ami-client

Dialer-optimized, multi-server AMI client for PHP/Laravel.

## Key Features

- Multi-server tick multiplexing with `AmiClientManager::tickAll()`.
- Non-blocking runtime contract (NBRC) for tick/connect/reconnect behavior.
- Strong per-node isolation across correlation, parsing, queues, and reconnect state.
- Bounded buffers/queues with backpressure controls.
- Typed actions plus `GenericAction` for arbitrary AMI actions.
- Laravel 12 adapter layer (`src/Laravel`) with `ami:listen`.

## Production Readiness

Use current gate artifacts as the source of truth (do not rely on hardcoded scores in README).

Resolve the latest files by UTC timestamp in filename:
- Latest score/verdict: `docs/ami-client/production-readiness/audits/*-production-readiness-score.md`
- Latest findings: `docs/ami-client/production-readiness/findings/*-findings.md`
- Latest chaos final suite: `docs/ami-client/chaos/reports/*-final-chaos-suite-results.md`
- Latest remediation report/index: `docs/ami-client/production-readiness/reports/*-pr-remediation-report.md` and `*-pr-remediation-artifacts-index.md`

If the latest chaos suite is not green, release notes must include an accepted-outcome decision record:
- [`docs/ami-client/production-readiness/decisions/accepted-chaos-outcome-template.md`](docs/ami-client/production-readiness/decisions/accepted-chaos-outcome-template.md)

## Install (Core)

```bash
composer require apntalk/ami-client
```

Requirements:
- PHP `>=8.4`

## Install (Laravel Adapter)

The core package is framework-agnostic. To use `src/Laravel/*`, install Laravel components in your app:

```bash
composer require illuminate/support illuminate/console
```

## Quickstart (Laravel 12)

```php
use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Protocol\Ping;

$manager = app(AmiClientManager::class);
$manager->default()->send(new Ping());
```

`GenericAction` example:

```php
use Apn\AmiClient\Cluster\AmiClientManager;
use Apn\AmiClient\Protocol\GenericAction;

app(AmiClientManager::class)
    ->server('node_1')
    ->send(new GenericAction('QueueSummary', ['Queue' => 'support']));
```

Start listener worker:

```bash
php artisan ami:listen --server=node_1
# or all configured servers
php artisan ami:listen --all
```

## Quickstart (Pure PHP)

```php
use Apn\AmiClient\Cluster\ConfigLoader;
use Psr\Log\NullLogger;

$config = require __DIR__ . '/config/ami-client.php';
$manager = ConfigLoader::load($config, new NullLogger());

while (true) {
    $manager->tickAll(25); // bounded selector wait (ms), avoids hot-spin
}
```

## Docs

- Usage guide: [`docs/ami-client/usage-guide.md`](docs/ami-client/usage-guide.md)
- NBRC: [`docs/contracts/non-blocking-runtime-contract.md`](docs/contracts/non-blocking-runtime-contract.md)
- Active batch pointer: [`docs/ACTIVE-BATCH.md`](docs/ACTIVE-BATCH.md)
- Delta index: [`docs/deltas/INDEX.md`](docs/deltas/INDEX.md)

## Contributing / Governance

NBRC is authoritative for runtime behavior. Execute work by active batch/task files, and keep delta/task updates batch-scoped rather than appending to large plan files.
