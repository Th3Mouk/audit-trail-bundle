<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A perfectly ordinary entity, deliberately not audited.
 *
 * Auditing is opt-in; this fixture is how a test proves that flushing an unmarked entity
 * records nothing at all.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_plain_entities')]
class PlainEntity
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
}
