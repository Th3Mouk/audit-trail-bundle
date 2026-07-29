<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Double;

use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;

/**
 * A gate whose verdict a test states outright.
 *
 * All gates must agree, so `refusingEverything()` is how a test proves one dissenting voice
 * silences a capture. Every question asked is recorded, which is how a test proves gates are
 * consulted for creates and deletes too, not only updates.
 */
final class FakeCaptureGate implements CaptureGateInterface
{
    /** @var list<array{entity: object, action: AuditAction}> */
    private array $questions = [];

    /**
     * @param \Closure(object, AuditAction): bool $verdict
     */
    private function __construct(
        private readonly \Closure $verdict,
    ) {
    }

    public static function allowingEverything(): self
    {
        return new self(static fn (object $entity, AuditAction $action): bool => true);
    }

    public static function refusingEverything(): self
    {
        return new self(static fn (object $entity, AuditAction $action): bool => false);
    }

    public static function refusing(string $entityClass): self
    {
        return new self(static fn (object $entity, AuditAction $action): bool => !$entity instanceof $entityClass);
    }

    public static function refusingAction(AuditAction $refused): self
    {
        return new self(static fn (object $entity, AuditAction $action): bool => $refused !== $action);
    }

    /**
     * @param \Closure(object, AuditAction): bool $verdict
     */
    public static function deciding(\Closure $verdict): self
    {
        return new self($verdict);
    }

    public function shouldCapture(object $entity, AuditAction $action): bool
    {
        $this->questions[] = ['entity' => $entity, 'action' => $action];

        return ($this->verdict)($entity, $action);
    }

    /**
     * @return list<array{entity: object, action: AuditAction}>
     */
    public function questions(): array
    {
        return $this->questions;
    }

    public function wasNeverAsked(): bool
    {
        return [] === $this->questions;
    }
}
