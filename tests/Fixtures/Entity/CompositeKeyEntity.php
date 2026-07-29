<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;
use Th3Mouk\AuditTrail\Attribute\Auditable;

/**
 * Audited yet keyed by two columns — the trail stores one identifier, so this must
 * fail loudly rather than silently record half a key.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_composite_key_entities')]
#[Auditable]
class CompositeKeyEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $tenant,
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $reference,
        #[ORM\Column(length: 128)]
        private string $label = '',
    ) {
    }

    public function getTenant(): string
    {
        return $this->tenant;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function relabel(string $label): void
    {
        $this->label = $label;
    }
}
