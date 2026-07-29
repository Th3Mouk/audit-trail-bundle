<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;

/**
 * Soft deleteable, and restorable by hand.
 *
 * The shared `SoftDeleteablePage` fixture exposes no way back from a logical delete, so a
 * restore — the other half of the soft-delete mapping — cannot be arranged with it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bridge_restorable_soft_deleteable_pages')]
#[Gedmo\SoftDeleteable(fieldName: 'deletedAt', timeAware: false)]
#[Auditable]
class RestorableSoftDeleteablePage
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

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function softDelete(\DateTimeImmutable $at): void
    {
        $this->deletedAt = $at;
    }

    public function restore(): void
    {
        $this->deletedAt = null;
    }
}
