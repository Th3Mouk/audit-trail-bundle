<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;

/**
 * An audited entity keyed by an application-assigned UUID v7.
 *
 * Post references it, which makes it the fixture that proves association capture keeps a
 * label snapshot and that identifiers are stringified rather than assumed to be integers.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_authors')]
#[Auditable]
class Author
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    public function __construct(#[AuditLabel]
        #[ORM\Column(length: 128)]
        private string $name, ?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
    }

    public function getId(): Uuid
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
