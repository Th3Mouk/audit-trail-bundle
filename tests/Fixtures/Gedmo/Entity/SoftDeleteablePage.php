<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Gedmo\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;

/**
 * Removing this entity is an UPDATE on the wire and a deletion to a reader.
 *
 * Gedmo's SoftDeleteable listener cancels the scheduled deletion and stamps `deletedAt`
 * instead, so unbridged capture would record "update: deletedAt set" where the trail
 * should read "deleted".
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_soft_deleteable_pages')]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false)]
#[Auditable]
class SoftDeleteablePage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(
        #[AuditLabel]
        #[ORM\Column(length: 255)]
        private string $title,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
