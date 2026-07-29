<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * Audited with no #[AuditLabel]: the label falls back to `__toString()`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_stringable_entities')]
#[Auditable]
class StringableEntity implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 128)]
        private string $name,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function __toString(): string
    {
        return \sprintf('Stringable %s', $this->name);
    }
}
