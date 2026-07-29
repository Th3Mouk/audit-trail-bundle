<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;

/**
 * Stamped by a Gedmo tracking listener on every update.
 *
 * Blameable rather than Timestampable on purpose: a string the test chooses changes visibly
 * between two flushes, where two timestamps taken within the same second would not.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bridge_stamped_pages')]
#[Auditable]
class StampedPage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Gedmo\Blameable(on: 'update')]
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $updatedBy = null;

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

    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }
}
