<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support;

use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditScope;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\StringableEntity;

/**
 * Anchored to a multi-word root class and naming no type, so the stored discriminator is
 * whatever the default derivation makes of `StringableEntity`.
 */
#[Auditable]
#[AuditScope(root: StringableEntity::class, via: 'holder')]
final readonly class CamelCaseRootedEntity
{
    public function __construct(
        public ?StringableEntity $holder = null,
    ) {
    }
}
