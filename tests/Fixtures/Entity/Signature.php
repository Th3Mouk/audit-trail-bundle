<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\AuditMasked;

/**
 * The innermost level: an embeddable inside an embeddable, so a dotted key two segments deep
 * (`certificate.signature.privateKey`) has to be resolved rather than read literally.
 */
#[ORM\Embeddable]
class Signature
{
    public function __construct(
        #[ORM\Column(length: 64)]
        private string $issuer,
        #[AuditMasked]
        #[ORM\Column(length: 255)]
        private string $privateKey,
    ) {
    }

    public function getIssuer(): string
    {
        return $this->issuer;
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function reissue(string $privateKey): void
    {
        $this->privateKey = $privateKey;
    }
}
