<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Fixture;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Th3Mouk\AuditTrail\Attribute\Auditable;
use Th3Mouk\AuditTrail\Attribute\AuditLabel;
use Th3Mouk\AuditTrail\Attribute\AuditMasked;
use Th3Mouk\AuditTrail\Attribute\NotAuditable;

/**
 * A translatable entity whose translated fields carry the three field policies.
 *
 * The shared Gedmo fixtures have no masked or ignored translatable field, so the claim
 * "a field policy applies in the translation path exactly as it does elsewhere" would be
 * untestable without this one.
 *
 * `reference` is the one column Gedmo never diverts. Without a plain column beside the
 * translatable ones, "the entity's own entry keeps its own columns and loses only the diverted
 * ones" could not be told apart from "the entity's own entry disappears".
 */
#[ORM\Entity]
#[ORM\Table(name: 'bridge_audited_translatable_pages')]
#[Gedmo\TranslationEntity(class: AuditedTranslatablePageTranslation::class)]
#[Auditable]
class AuditedTranslatablePage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[AuditMasked]
    #[Gedmo\Translatable]
    #[ORM\Column(length: 255)]
    private string $secret = 'first secret';

    #[NotAuditable]
    #[Gedmo\Translatable]
    #[ORM\Column(length: 255)]
    private string $note = 'first note';

    #[ORM\Column(length: 255)]
    private string $reference = 'REF-1';

    #[Gedmo\Locale]
    private ?string $locale = null;

    /** @var Collection<int, AuditedTranslatablePageTranslation> */
    #[ORM\OneToMany(targetEntity: AuditedTranslatablePageTranslation::class, mappedBy: 'object', cascade: ['persist', 'remove'])]
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

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function changeSecret(string $secret): void
    {
        $this->secret = $secret;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function annotate(string $note): void
    {
        $this->note = $note;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function renumber(string $reference): void
    {
        $this->reference = $reference;
    }

    public function translateInto(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * @return Collection<int, AuditedTranslatablePageTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }
}
