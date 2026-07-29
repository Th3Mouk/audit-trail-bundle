<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * Inherits the opt-in and revokes it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_opted_out_children')]
#[Auditable(enabled: false)]
class OptedOutChild extends AuditableBase
{
}
