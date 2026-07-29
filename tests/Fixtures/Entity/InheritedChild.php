<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Audited purely by inheritance: it declares no attribute of its own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_inherited_children')]
class InheritedChild extends AuditableBase
{
}
