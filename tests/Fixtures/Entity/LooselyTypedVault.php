<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;

/**
 * The same secrets as {@see Vault}, embedded through properties that carry no PHP type.
 *
 * `#[ORM\Embedded(class: …)]` names the class itself, so Doctrine is perfectly happy and produces
 * the same dotted change-set keys. A resolver that reads the embeddable's class off the property's
 * *type* learns nothing here, and a policy lookup that fails silently falls back to "tracked" —
 * which writes the masked secret out in clear. This fixture is that failure, pinned.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_loosely_typed_vaults')]
#[Auditable]
class LooselyTypedVault
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param Credentials $credentials
     * @param Certificate $certificate
     */
    public function __construct(
        #[AuditLabel]
        #[ORM\Column(length: 128)]
        private string $name,
        #[ORM\Embedded(class: Credentials::class)]
        private $credentials,
        #[ORM\Embedded(class: Certificate::class)]
        private $certificate,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCredentials(): Credentials
    {
        return $this->credentials;
    }

    public function getCertificate(): Certificate
    {
        return $this->certificate;
    }
}
