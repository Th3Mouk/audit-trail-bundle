<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An embeddable that embeds another one, and declares it without a PHP type — the nested
 * equivalent of {@see LooselyTypedVault}.
 */
#[ORM\Embeddable]
class Certificate
{
    /**
     * @param Signature $signature
     */
    public function __construct(
        #[ORM\Column(length: 64)]
        private string $serial,
        #[ORM\Embedded(class: Signature::class)]
        private $signature,
    ) {
    }

    public function getSerial(): string
    {
        return $this->serial;
    }

    public function getSignature(): Signature
    {
        return $this->signature;
    }
}
