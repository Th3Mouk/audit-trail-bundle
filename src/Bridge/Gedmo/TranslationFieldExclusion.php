<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Bridge\Gedmo;

use Doctrine\ORM\EntityManagerInterface;
use Th3Mouk\AuditTrail\Capture\FieldExclusionInterface;

/**
 * Keeps the translated entity's own history from claiming a column change Gedmo reverts.
 *
 * Capture runs before Gedmo's `TranslatableListener`, which is the only way to read a
 * translatable field's before/after at all — but for a non-default locale Gedmo then takes
 * those fields back out of the entity's change set and writes them to a translation row
 * instead. Left alone, the entity's ordinary `update` entry would assert a change to a column
 * that never happens. The content itself is not lost: `TranslationAuditListener` records it as
 * a translation entry against the same entity.
 *
 * A thin adapter on purpose. `TranslationAuditListener::translationOnlyFields()` already knows
 * which fields those are and already resolves the effective locale the same way the listener
 * does; duplicating either here is how the two would drift apart. This class exists so the
 * capture pipeline can be handed a `FieldExclusionInterface` without the core ever naming
 * Gedmo, and so the exclusion disappears together with the rest of the translatable piece when
 * `bridges.gedmo.translatable` is false.
 */
final readonly class TranslationFieldExclusion implements FieldExclusionInterface
{
    public function __construct(
        private TranslationAuditListener $translationAuditListener,
    ) {
    }

    public function excludedFields(EntityManagerInterface $entityManager, object $entity): array
    {
        return $this->translationAuditListener->translationOnlyFields($entityManager, $entity);
    }
}
