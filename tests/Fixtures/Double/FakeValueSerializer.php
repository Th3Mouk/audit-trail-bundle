<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Double;

use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;

/**
 * A value serializer whose support and output a test decides in one line.
 *
 * Use the named constructors to say what the chain should do — `supportingNothing()` to
 * prove a fallback, `forInstancesOf()` to prove priority, `alwaysReturning()` to freeze the
 * output of a whole capture. Every call is recorded so a test can assert the chain stopped
 * where it was supposed to.
 */
final class FakeValueSerializer implements ValueSerializerInterface
{
    /** @var list<mixed> */
    private array $supportsCalls = [];

    /** @var list<mixed> */
    private array $serializeCalls = [];

    /**
     * @param \Closure(mixed): bool  $supports
     * @param \Closure(mixed): mixed $serialize
     */
    private function __construct(
        private readonly \Closure $supports,
        private readonly \Closure $serialize,
    ) {
    }

    public static function alwaysReturning(mixed $value): self
    {
        return new self(static fn (mixed $candidate): bool => true, static fn (mixed $candidate): mixed => $value);
    }

    public static function supportingNothing(): self
    {
        return new self(static fn (mixed $candidate): bool => false, static fn (mixed $candidate): mixed => $candidate);
    }

    /**
     * @param (\Closure(mixed): mixed)|null $serialize
     */
    public static function forInstancesOf(string $class, ?\Closure $serialize = null): self
    {
        return new self(
            static fn (mixed $candidate): bool => $candidate instanceof $class,
            $serialize ?? static fn (mixed $candidate): mixed => (string) $candidate,
        );
    }

    /**
     * @param \Closure(mixed): bool         $supports
     * @param (\Closure(mixed): mixed)|null $serialize
     */
    public static function deciding(\Closure $supports, ?\Closure $serialize = null): self
    {
        return new self($supports, $serialize ?? static fn (mixed $candidate): mixed => $candidate);
    }

    public function supports(mixed $value): bool
    {
        $this->supportsCalls[] = $value;

        return ($this->supports)($value);
    }

    public function serialize(mixed $value): mixed
    {
        $this->serializeCalls[] = $value;

        return ($this->serialize)($value);
    }

    /**
     * @return list<mixed>
     */
    public function valuesOffered(): array
    {
        return $this->supportsCalls;
    }

    /**
     * @return list<mixed>
     */
    public function valuesSerialized(): array
    {
        return $this->serializeCalls;
    }

    public function wasNeverUsed(): bool
    {
        return [] === $this->serializeCalls;
    }
}
