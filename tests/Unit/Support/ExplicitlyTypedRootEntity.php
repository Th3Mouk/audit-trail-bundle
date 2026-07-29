<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support;

use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditScope;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\StringableEntity;

/**
 * Names its own discriminator, so the trail keeps reading the same after the root class is renamed.
 */
#[Auditable]
#[AuditScope(root: StringableEntity::class, via: 'holder', type: 'landing_page')]
final readonly class ExplicitlyTypedRootEntity
{
    public function __construct(
        public ?StringableEntity $holder = null,
    ) {
    }
}
