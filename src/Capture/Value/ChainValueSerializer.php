<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Capture\Value;

use Psr\Log\LoggerInterface;
use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;

/**
 * The value serializer the rest of the bundle talks to: a priority chain, first match wins.
 *
 * A value nothing can handle is excluded rather than guessed at, because a wrong "after" in an
 * audit trail is worse than a missing one. The exclusion is reported once per value class, at
 * warning level, so an unhandled value object shows up in the logs as a single actionable line
 * instead of one per flushed row. Register a handler for it with the
 * `audit_trail.value_serializer` tag.
 */
final class ChainValueSerializer implements ValueSerializerInterface
{
    /** @var array<string, true> */
    private array $reported = [];

    /**
     * @param iterable<ValueSerializerInterface> $serializers
     */
    public function __construct(
        private readonly iterable $serializers,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function supports(mixed $value): bool
    {
        if (null !== $this->firstSupporting($value)) {
            return true;
        }

        $this->reportOnce($value);

        return false;
    }

    public function serialize(mixed $value): mixed
    {
        return $this->firstSupporting($value)?->serialize($value);
    }

    private function firstSupporting(mixed $value): ?ValueSerializerInterface
    {
        foreach ($this->serializers as $serializer) {
            if ($serializer->supports($value)) {
                return $serializer;
            }
        }

        return null;
    }

    private function reportOnce(mixed $value): void
    {
        $type = get_debug_type($value);

        if (isset($this->reported[$type])) {
            return;
        }

        $this->reported[$type] = true;

        $this->logger?->warning(
            'Audit trail excluded a value of type "{type}": no value serializer supports it. Register one with the "audit_trail.value_serializer" tag.',
            ['type' => $type],
        );
    }
}
