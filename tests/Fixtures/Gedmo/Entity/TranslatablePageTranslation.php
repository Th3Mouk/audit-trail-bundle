<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Gedmo\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;

/**
 * The personal-translation table for TranslatablePage.
 *
 * Gedmo's mapped superclass supplies every column except the owning association, which
 * each application maps itself — that is what "personal" translations means.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_translatable_page_translations')]
#[ORM\UniqueConstraint(name: 'fixture_page_translation_unique', columns: ['locale', 'object_id', 'field'])]
class TranslatablePageTranslation extends AbstractPersonalTranslation
{
    #[ORM\ManyToOne(targetEntity: TranslatablePage::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected $object;

    /**
     * Gedmo instantiates translations with no arguments and fills them through setters, so
     * every argument stays optional. Assigning only what was given keeps the parent's
     * non-nullable columns honest.
     */
    public function __construct(?string $locale = null, ?string $field = null, ?string $content = null)
    {
        if (null !== $locale) {
            $this->setLocale($locale);
        }

        if (null !== $field) {
            $this->setField($field);
        }

        if (null !== $content) {
            $this->setContent($content);
        }
    }
}
