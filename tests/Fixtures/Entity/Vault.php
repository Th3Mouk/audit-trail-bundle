<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;

/**
 * An audited entity whose sensitive fields live one level down, inside an embeddable.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_vaults')]
#[Auditable]
class Vault
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[AuditLabel]
        #[ORM\Column(length: 128)]
        private string $name,
        #[ORM\Embedded(class: Credentials::class)]
        private Credentials $credentials,
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

    public function getCredentials(): Credentials
    {
        return $this->credentials;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }
}
