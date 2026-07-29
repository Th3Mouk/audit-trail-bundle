<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditMasked;
use Th3Mouk\AuditTrail\Attribute\NotAuditable;

/**
 * Asks for two mutually exclusive field policies on one property.
 *
 * "Drop the change" and "record the change without its value" cannot both be true;
 * reading this entity's metadata is expected to explain that rather than guess.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_conflicting_policy_entities')]
#[Auditable]
class ConflictingPolicyEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[NotAuditable]
        #[AuditMasked]
        #[ORM\Column(length: 128)]
        private string $token,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
