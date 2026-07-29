<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;

/**
 * The personal-translation table of AuditedTranslatablePage.
 *
 * Gedmo needs a mapped translation entity to write to, otherwise its listener falls back to
 * `Gedmo\Translatable\Entity\Translation`, whose table this fixture application does not map.
 */
#[ORM\Entity]
#[ORM\Table(name: 'bridge_audited_translatable_page_translations')]
#[ORM\UniqueConstraint(name: 'bridge_audited_page_translation_unique', columns: ['locale', 'object_id', 'field'])]
class AuditedTranslatablePageTranslation extends AbstractPersonalTranslation
{
    #[ORM\ManyToOne(targetEntity: AuditedTranslatablePage::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected $object;
}
