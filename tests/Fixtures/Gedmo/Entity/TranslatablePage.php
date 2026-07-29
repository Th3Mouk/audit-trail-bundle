<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Gedmo\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;

/**
 * Translatable and audited at once — the reason listener priority is configurable.
 *
 * Gedmo's Translatable listener rewrites the change set during onFlush: it moves a
 * translated value out of the entity's own change set and into a translation row. Capture
 * therefore has to observe the change set before Gedmo touches it, or a title change in a
 * non-default locale disappears from the trail.
 *
 * Lives outside the shared fixture namespace so the Doctrine-only kernel never maps it,
 * which keeps "the bundle boots without gedmo/doctrine-extensions" an honest claim.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fixture_translatable_pages')]
#[Gedmo\TranslationEntity(class: TranslatablePageTranslation::class)]
#[Auditable]
class TranslatablePage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Gedmo\Translatable]
    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[Gedmo\Locale]
    private ?string $locale = null;

    /** @var Collection<int, TranslatablePageTranslation> */
    #[ORM\OneToMany(targetEntity: TranslatablePageTranslation::class, mappedBy: 'object', cascade: ['persist', 'remove'])]
    private Collection $translations;

    public function __construct(#[AuditLabel]
        #[Gedmo\Translatable]
        #[ORM\Column(length: 255)]
        private string $title)
    {
        $this->translations = new ArrayCollection();
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

    public function getBody(): string
    {
        return $this->body;
    }

    public function rewrite(string $body): void
    {
        $this->body = $body;
    }

    public function translateInto(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * @return Collection<int, TranslatablePageTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(TranslatablePageTranslation $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setObject($this);
        }
    }
}
