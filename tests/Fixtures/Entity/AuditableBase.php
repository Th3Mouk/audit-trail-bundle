<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;

/**
 * Carries the opt-in for a whole branch of the hierarchy.
 *
 * PHP does not report inherited class attributes through reflection, so a metadata reader
 * has to walk the parents itself. InheritedChild and OptedOutChild are the two answers
 * that walk must produce.
 */
#[ORM\MappedSuperclass]
#[Auditable]
abstract class AuditableBase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;

    public function __construct(
        #[AuditLabel]
        #[ORM\Column(length: 128)]
        protected string $name,
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
