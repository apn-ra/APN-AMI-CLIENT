<?php

declare(strict_types=1);

namespace Tests\Unit\Correlation;

use Apn\AmiClient\Core\Contracts\CompletionStrategyInterface;
use Apn\AmiClient\Core\Contracts\MetricsCollectorInterface;
use Apn\AmiClient\Correlation\CorrelationRegistry;
use Apn\AmiClient\Exceptions\ActionErrorResponseException;
use Apn\AmiClient\Protocol\Action;
use Apn\AmiClient\Protocol\Event;
use Apn\AmiClient\Protocol\Response;
use Apn\AmiClient\Protocol\Strategies\FollowsResponseStrategy;
use Apn\AmiClient\Protocol\Strategies\MultiEventStrategy;
use Apn\AmiClient\Protocol\Strategies\SingleResponseStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class PermissionErrorCorrelationTest extends TestCase
{
    #[DataProvider('errorStrategies')]
    public function test_error_response_rejects_pending_action_with_structured_exception(CompletionStrategyInterface $strategy): void
    {
        $registry = new CorrelationRegistry(labels: ['server_key' => 'node-a']);
        $pending = $registry->register(new PermissionMockAction('node-a:inst:1', $strategy));

        $captured = null;
        $pending->onComplete(function (?\Throwable $e) use (&$captured): void {
            $captured = $e;
        });

        $registry->handleResponse(new Response([
            'response' => 'Error',
            'actionid' => 'node-a:inst:1',
            'message' => 'Permission denied',
        ]));

        $this->assertInstanceOf(ActionErrorResponseException::class, $captured);
        $this->assertSame('node-a:inst:1', $captured->getActionId());
        $this->assertSame('Permission denied', $captured->getAmiMessage());
    }

    public function test_missing_actionid_response_is_logged_and_counted_as_unmatched(): void
    {
        $logger = new RecordingLogger();
        $metrics = new RecordingMetrics();
        $registry = new CorrelationRegistry(logger: $logger, metrics: $metrics, labels: ['server_key' => 'node-x']);

        $registry->handleResponse($this->fixtureResponse('permission-error-missing-actionid-crlf.raw'));

        $this->assertSame(1, $metrics->count('ami_correlation_unmatched_responses_total'));
        $warning = $logger->first('warning', 'Correlation response unmatched');
        $this->assertNotNull($warning);
        $this->assertSame('node-x', $warning['context']['server_key']);
        $this->assertNull($warning['context']['action_id']);
        $this->assertSame('error', $warning['context']['response_type']);
    }

    public function test_unknown_actionid_response_is_logged_and_counted_as_unmatched(): void
    {
        $logger = new RecordingLogger();
        $metrics = new RecordingMetrics();
        $registry = new CorrelationRegistry(logger: $logger, metrics: $metrics, labels: ['server_key' => 'node-y']);

        $registry->handleResponse(new Response([
            'response' => 'Error',
            'actionid' => 'node-y:missing:99',
            'message' => 'Permission denied',
        ]));

        $this->assertSame(1, $metrics->count('ami_correlation_unmatched_responses_total'));
        $warning = $logger->first('warning', 'Correlation response unmatched');
        $this->assertNotNull($warning);
        $this->assertSame('node-y:missing:99', $warning['context']['action_id']);
        $this->assertSame('error', $warning['context']['response_type']);
    }

    public function test_decision_logs_include_matched_and_failed_markers_with_strategy_context(): void
    {
        $logger = new RecordingLogger();
        $registry = new CorrelationRegistry(logger: $logger, labels: ['server_key' => 'node-z']);
        $registry->register(new PermissionMockAction('node-z:inst:7', new SingleResponseStrategy()));

        $registry->handleResponse(new Response([
            'response' => 'Error',
            'actionid' => 'node-z:inst:7',
            'message' => 'Permission denied',
        ]));

        $matched = $logger->firstDecision('matched');
        $failed = $logger->firstDecision('failed');

        $this->assertNotNull($matched);
        $this->assertNotNull($failed);
        $this->assertSame('matched', $matched['context']['decision']);
        $this->assertSame(SingleResponseStrategy::class, $matched['context']['strategy']);
        $this->assertSame('error', $matched['context']['response_type']);
        $this->assertSame('failed', $failed['context']['decision']);
    }

    /**
     * @return array<string, array{0: CompletionStrategyInterface}>
     */
    public static function errorStrategies(): array
    {
        return [
            'single' => [new SingleResponseStrategy()],
            'multi' => [new MultiEventStrategy('QueueStatusComplete')],
            'follows' => [new FollowsResponseStrategy()],
        ];
    }

    private function fixtureResponse(string $name): Response
    {
        $path = __DIR__ . '/../../../docs/ami-client/fixtures/permission-errors/' . $name;
        self::assertFileExists($path);
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $headers = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }

        return new Response($headers);
    }
}

final readonly class PermissionMockAction extends Action
{
    public function __construct(string $actionId, CompletionStrategyInterface $strategy)
    {
        parent::__construct([], $actionId, $strategy);
    }

    public function getActionName(): string
    {
        return 'MockPermission';
    }

    public function withActionId(string $actionId): static
    {
        return new self($actionId, $this->strategy);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return array{level: string, message: string, context: array<string, mixed>}|null */
    public function first(string $level, string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && $record['message'] === $message) {
                return $record;
            }
        }

        return null;
    }

    /** @return array{level: string, message: string, context: array<string, mixed>}|null */
    public function firstDecision(string $decision): ?array
    {
        foreach ($this->records as $record) {
            if (
                $record['level'] === 'debug'
                && $record['message'] === 'Correlation response decision'
                && (($record['context']['decision'] ?? null) === $decision)
            ) {
                return $record;
            }
        }

        return null;
    }
}

final class RecordingMetrics implements MetricsCollectorInterface
{
    /** @var array<int, array{name: string, labels: array<string, mixed>, amount: int}> */
    public array $increments = [];

    public function increment(string $name, array $labels = [], int $amount = 1): void
    {
        $this->increments[] = ['name' => $name, 'labels' => $labels, 'amount' => $amount];
    }

    public function record(string $name, float $value, array $labels = []): void
    {
    }

    public function set(string $name, float $value, array $labels = []): void
    {
    }

    public function count(string $name): int
    {
        $count = 0;
        foreach ($this->increments as $increment) {
            if ($increment['name'] === $name) {
                $count += $increment['amount'];
            }
        }

        return $count;
    }
}
