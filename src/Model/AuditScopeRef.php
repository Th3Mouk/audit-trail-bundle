<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Model;

/**
 * The aggregate an entry belongs to, denormalised onto the entry itself.
 *
 * `type` is a short discriminator rather than a class name, so it survives refactors
 * and reads well as a filter value (`?rootType=questionnaire`).
 */
final readonly class AuditScopeRef
{
    public function __construct(
        public string $type,
        public string $id,
        public ?string $label = null,
    ) {
    }

    public static function of(string $type, string|int $id, ?string $label = null): self
    {
        return new self($type, (string) $id, $label);
    }
}
