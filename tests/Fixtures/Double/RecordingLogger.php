<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Double;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Collects log records so a test can assert on them.
 *
 * The capture pipeline reports an unserialisable value by warning once rather than by
 * throwing; "once" is a claim only a counted log can back up.
 *
 * Given an inner logger it forwards as well as records, which is how the test kernels watch
 * the application logger without silencing it.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $records = [];

    public function __construct(
        private readonly ?LoggerInterface $next = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];

        $this->next?->log($level, $message, $context);
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function records(?string $level = null): array
    {
        if (null === $level) {
            return $this->records;
        }

        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['level'] === $level,
        ));
    }

    /**
     * @return list<string>
     */
    public function messages(?string $level = null): array
    {
        return array_map(static fn (array $record): string => $record['message'], $this->records($level));
    }

    public function isEmpty(): bool
    {
        return [] === $this->records;
    }
}
