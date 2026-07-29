<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Bridge\Gedmo;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractTranslation;
use Gedmo\Translatable\Entity\Translation;
use Gedmo\Translatable\TranslatableListener;
use Psr\Log\LoggerInterface;
use Th3Mouk\AuditTrail\AuditLoggerInterface;
use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Capture\EntityIdResolver;
use Th3Mouk\AuditTrail\Capture\LabelResolverInterface;
use Th3Mouk\AuditTrail\Capture\ScopeResolverInterface;
use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Metadata\FieldPolicyResolver;
use Th3Mouk\AuditTrail\Model\AuditScopeRef;
use Th3Mouk\AuditTrail\Storage\FlushState;

/**
 * Audits translated content: who changed which field, in which locale, from what to what.
 *
 * A translation is a change to an entity's content, not a change to a side table nobody asked
 * about, so entries are recorded **against the translated entity** — they land in that entity's
 * own history and in the aggregate feed of its audit root. Shape:
 *
 * ```
 * {"title": {"locale": "fr", "before": "Ancien titre", "after": "Nouveau titre"}}
 * ```
 *
 * One entry per (entity, locale) pair, carrying the locale and the translation class in its
 * metadata.
 *
 * Coverage is driven by the *translated entity's* change set rather than by the translation rows
 * Gedmo writes, because Gedmo writes those rows two different ways inside its own `onFlush`:
 * through the UnitOfWork, and — when the parent has no identifier yet — through a direct DBAL
 * insert that bypasses it entirely. Both start from the same change set, so reading the change
 * set is the only thing that sees both. Translation objects an application persists itself are
 * picked up as well. `README.md` in this directory states the exact coverage boundary and the
 * ordering this listener needs relative to Gedmo's own.
 *
 * Must run **before** Gedmo's own `TranslatableListener`, which reverts translatable fields out
 * of the change set this class reads. `bridges.gedmo.listener_priority` carries that ordering
 * and defaults strictly above Gedmo's own priority of 0; below it, this listener finds nothing
 * and records nothing, with no error to show for it.
 *
 * Reads the UnitOfWork and class metadata only: no query, no lazy association initialised. Needs
 * Gedmo's listener to know the active locale; without it this listener does nothing and the
 * bundle boots unaffected.
 */
final class TranslationAuditListener
{
    /**
     * @var array<string, \ReflectionProperty|null>
     */
    private array $localeProperties = [];

    /**
     * @var array<string, true>
     */
    private array $reported = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly AuditableResolver $auditableResolver,
        private readonly FieldPolicyResolver $fieldPolicyResolver,
        private readonly ValueSerializerInterface $valueSerializer,
        private readonly EntityIdResolver $entityIdResolver,
        private readonly LabelResolverInterface $labelResolver,
        private readonly ScopeResolverInterface $scopeResolver,
        private readonly CaptureGateInterface $captureGate,
        private readonly FlushState $flushState,
        private readonly ?TranslatableListener $translatableListener = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The flush is marked here as well as in `AuditLogListener`, and for the same reason.
     *
     * `FlushState` describes the flush, not one listener's turn inside it: a mark only lasts for
     * the callback that made it, and `AuditLogListener` clears its own long before Doctrine's
     * `commit()` is done. Whichever of the two listeners runs second — the priorities are
     * independent — is still inside that flush and has to say so. Left unmarked, the rows this
     * one records would be persisted into a unit of work whose change sets are already computed
     * and never get one of their own, which the insert persister turns into a duplicate primary
     * key rather than into a missing row.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        if (null === $this->translatableListener) {
            return;
        }

        $entityManager = $args->getObjectManager();

        // The same guard, and the same reason, as AuditLogListener: Doctrine puts a listener on
        // every connection unless the tag names one, and storage belongs to the audited manager.
        // Recording a translation flushed elsewhere would enlist the entry in a unit of work other
        // than the one that produced the change — outside its transaction, where a rollback would
        // no longer take it along. Before enterFlush(), so a foreign flush is not even marked.
        if ($entityManager !== $this->entityManager) {
            return;
        }

        $this->flushState->enterFlush();

        try {
            $this->record($entityManager);
        } finally {
            $this->flushState->leaveFlush();
        }
    }

    private function record(EntityManagerInterface $entityManager): void
    {
        $unitOfWork = $entityManager->getUnitOfWork();

        $insertions = $unitOfWork->getScheduledEntityInsertions();
        $updates = $unitOfWork->getScheduledEntityUpdates();

        $drafts = [];

        foreach ([...$insertions, ...$updates] as $entity) {
            $this->collectFromTranslatedEntity($entityManager, $unitOfWork, $entity, $drafts);
        }

        foreach ($insertions as $translation) {
            $this->collectFromTranslationObject($entityManager, $unitOfWork, $translation, true, $drafts);
        }

        foreach ($updates as $translation) {
            $this->collectFromTranslationObject($entityManager, $unitOfWork, $translation, false, $drafts);
        }

        foreach ($drafts as $draft) {
            $this->auditLogger->updated(
                $draft['class'],
                $draft['id'],
                $draft['changes'],
                $draft['label'],
                $draft['root'],
                [
                    'locale' => $draft['locale'],
                    'translation_class' => $draft['translationClass'],
                ],
            );
        }
    }

    /**
     * Translatable fields whose change Gedmo redirects to a translation row, leaving the entity's
     * own columns untouched.
     *
     * A capture pipeline running before Gedmo — as it must, to read change sets before they are
     * rewritten — still finds those fields in an UPDATE change set, and would report a column
     * change that never reaches the table. Subtracting these keeps the entity's own history
     * honest; the content itself is not lost, it becomes a translation entry instead. Not
     * meaningful for insertions: on insert Gedmo writes the value to the entity's own columns as
     * well.
     *
     * `TranslationFieldExclusion` is what hands this to `AuditLogListener`, as a
     * `FieldExclusionInterface`, so the core never names Gedmo. Public because it is also useful
     * on its own.
     *
     * @return list<string>
     */
    public function translationOnlyFields(EntityManagerInterface $entityManager, object $entity): array
    {
        if (null === $this->translatableListener) {
            return [];
        }

        $configuration = $this->translatableListener->getConfiguration($entityManager, $entity::class);
        $fields = $configuration['fields'] ?? [];

        if ([] === $fields) {
            return [];
        }

        $locale = $this->localeOf($entity, $configuration['locale'] ?? null);

        if (null === $locale || $locale === $this->translatableListener->getDefaultLocale()) {
            return [];
        }

        return array_values($fields);
    }

    /**
     * @param array<string, array{class: class-string, id: string, locale: string, translationClass: class-string, label: string|null, root: AuditScopeRef|null, changes: array<string, array{locale: string, before: mixed, after: mixed}>}> $drafts
     */
    private function collectFromTranslatedEntity(
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        object $entity,
        array &$drafts,
    ): void {
        \assert(null !== $this->translatableListener);

        $class = $entity::class;

        if (!$this->auditableResolver->isAuditable($class)) {
            return;
        }

        $configuration = $this->translatableListener->getConfiguration($entityManager, $class);
        $fields = $configuration['fields'] ?? [];

        if ([] === $fields) {
            return;
        }

        $locale = $this->localeOf($entity, $configuration['locale'] ?? null);

        if (null === $locale || $locale === $this->translatableListener->getDefaultLocale()) {
            return;
        }

        $changeSet = $unitOfWork->getEntityChangeSet($entity);
        $changes = [];

        foreach ($fields as $field) {
            if (!\array_key_exists($field, $changeSet)) {
                continue;
            }

            $change = $this->describeChange($class, $field, $locale, $changeSet[$field][0] ?? null, $changeSet[$field][1] ?? null);

            if (null !== $change) {
                $changes[$field] = $change;
            }
        }

        if ([] === $changes || !$this->captureGate->shouldCapture($entity, AuditAction::Update)) {
            return;
        }

        $identifier = $this->entityIdResolver->resolve($entity);

        if (null === $identifier) {
            $this->reportMissingIdentifier($class);

            return;
        }

        $this->addDraft($drafts, $class, $identifier, $locale, $this->translationClassOf($configuration), $entity, $changes);
    }

    /**
     * @param array<string, array{class: class-string, id: string, locale: string, translationClass: class-string, label: string|null, root: AuditScopeRef|null, changes: array<string, array{locale: string, before: mixed, after: mixed}>}> $drafts
     */
    private function collectFromTranslationObject(
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        object $translation,
        bool $isInsertion,
        array &$drafts,
    ): void {
        \assert(null !== $this->translatableListener);

        if (!$translation instanceof AbstractPersonalTranslation && !$translation instanceof AbstractTranslation) {
            return;
        }

        $locale = $translation->getLocale();
        $field = $translation->getField();

        if (!\is_string($locale) || '' === $locale || !\is_string($field) || '' === $field) {
            return;
        }

        if ($translation instanceof AbstractPersonalTranslation) {
            [$class, $identifier, $source] = $this->translatedByPersonalTranslation($translation);
        } else {
            [$class, $identifier, $source] = $this->translatedByForeignKey($entityManager, $unitOfWork, $translation);
        }

        if (null === $class || null === $identifier || !$this->auditableResolver->isAuditable($class)) {
            return;
        }

        $configuration = $this->translatableListener->getConfiguration($entityManager, $class);

        if (!\in_array($field, $configuration['fields'] ?? [], true)) {
            return;
        }

        $contentChange = $isInsertion
            ? [null, $translation->getContent()]
            : ($unitOfWork->getEntityChangeSet($translation)['content'] ?? null);

        if (null === $contentChange) {
            return;
        }

        $change = $this->describeChange($class, $field, $locale, $contentChange[0] ?? null, $contentChange[1] ?? null);

        if (null === $change) {
            return;
        }

        $source = null !== $source && !$unitOfWork->isUninitializedObject($source) ? $source : null;

        if (null === $source) {
            $this->reportUnloadedSource($class);

            return;
        }

        if (!$this->captureGate->shouldCapture($source, AuditAction::Update)) {
            return;
        }

        $this->addDraft($drafts, $class, $identifier, $locale, $translation::class, $source, [$field => $change]);
    }

    /**
     * @return array{0: class-string|null, 1: string|null, 2: object|null}
     */
    private function translatedByPersonalTranslation(AbstractPersonalTranslation $translation): array
    {
        $source = $translation->getObject();

        if (!\is_object($source)) {
            return [null, null, null];
        }

        return [$source::class, $this->entityIdResolver->resolve($source), $source];
    }

    /**
     * @return array{0: class-string|null, 1: string|null, 2: object|null}
     */
    private function translatedByForeignKey(
        EntityManagerInterface $entityManager,
        UnitOfWork $unitOfWork,
        AbstractTranslation $translation,
    ): array {
        $class = $translation->getObjectClass();
        $foreignKey = $translation->getForeignKey();

        if (!\is_string($class) || !class_exists($class) || null === $foreignKey || '' === (string) $foreignKey) {
            return [null, null, null];
        }

        $identifier = (string) $foreignKey;
        $source = $unitOfWork->tryGetById($identifier, $entityManager->getClassMetadata($class)->rootEntityName);

        return [$class, $identifier, false === $source ? null : $source];
    }

    /**
     * @param class-string $class
     *
     * @return array{locale: string, before: mixed, after: mixed}|null
     */
    private function describeChange(string $class, string $field, string $locale, mixed $before, mixed $after): ?array
    {
        $policy = $this->fieldPolicyResolver->policyFor($class, $field);

        if (!$policy->isRowTriggering()) {
            return null;
        }

        if (!$policy->recordsValue()) {
            $mask = $this->fieldPolicyResolver->maskFor($class, $field);

            return ['locale' => $locale, 'before' => $mask, 'after' => $mask];
        }

        if (!$this->valueSerializer->supports($before) || !$this->valueSerializer->supports($after)) {
            return null;
        }

        return [
            'locale' => $locale,
            'before' => $this->valueSerializer->serialize($before),
            'after' => $this->valueSerializer->serialize($after),
        ];
    }

    /**
     * @param array<string, array{class: class-string, id: string, locale: string, translationClass: class-string, label: string|null, root: AuditScopeRef|null, changes: array<string, array{locale: string, before: mixed, after: mixed}>}> $drafts
     * @param class-string                                                                                                                                                                                                                    $class
     * @param class-string                                                                                                                                                                                                                    $translationClass
     * @param array<string, array{locale: string, before: mixed, after: mixed}>                                                                                                                                                               $changes
     */
    private function addDraft(
        array &$drafts,
        string $class,
        string $identifier,
        string $locale,
        string $translationClass,
        object $source,
        array $changes,
    ): void {
        $key = $class."\0".$identifier."\0".$locale;

        $drafts[$key] ??= [
            'class' => $class,
            'id' => $identifier,
            'locale' => $locale,
            'translationClass' => $translationClass,
            'label' => $this->labelResolver->resolve($source),
            'root' => $this->scopeRefOf($source),
            'changes' => [],
        ];

        foreach ($changes as $field => $change) {
            $drafts[$key]['changes'][$field] ??= $change;
        }
    }

    private function scopeRefOf(object $entity): ?AuditScopeRef
    {
        $root = $this->scopeResolver->resolve($entity);

        return null === $root ? null : AuditScopeRef::of($root->type, $root->id, $root->label);
    }

    private function localeOf(object $entity, ?string $localeProperty): ?string
    {
        \assert(null !== $this->translatableListener);

        $value = null !== $localeProperty ? $this->readProperty($entity, $localeProperty) : null;

        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        if (\is_string($value) && '' !== $value) {
            return $value;
        }

        $locale = $this->translatableListener->getListenerLocale();

        return '' !== $locale ? $locale : null;
    }

    private function readProperty(object $entity, string $property): mixed
    {
        $key = $entity::class.'::'.$property;

        if (!\array_key_exists($key, $this->localeProperties)) {
            $this->localeProperties[$key] = $this->findProperty($entity::class, $property);
        }

        $reflection = $this->localeProperties[$key];

        if (null === $reflection || !$reflection->isInitialized($entity)) {
            return null;
        }

        return $reflection->getValue($entity);
    }

    /**
     * @param class-string $class
     */
    private function findProperty(string $class, string $property): ?\ReflectionProperty
    {
        for ($level = new \ReflectionClass($class); false !== $level; $level = $level->getParentClass()) {
            if ($level->hasProperty($property)) {
                return $level->getProperty($property);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return class-string
     */
    private function translationClassOf(array $configuration): string
    {
        $translationClass = $configuration['translationClass'] ?? null;

        return \is_string($translationClass) && class_exists($translationClass) ? $translationClass : Translation::class;
    }

    private function reportMissingIdentifier(string $class): void
    {
        $this->reportOnce(
            'identifier:'.$class,
            'Audit trail cannot record translated content for "{class}": the entity still has no identifier when onFlush '
            .'runs, which is also the case in which Gedmo inserts its translation rows straight through the DBAL '
            .'connection, out of reach of the UnitOfWork. Assign identifiers before flush (UUID) to make translations of '
            .'newly created entities auditable.',
            ['class' => $class],
        );
    }

    private function reportUnloadedSource(string $class): void
    {
        $this->reportOnce(
            'source:'.$class,
            'Audit trail skipped a translation of "{class}": the translated entity is not loaded, so capture gates cannot '
            .'be evaluated for it and the entry is dropped rather than recorded past a gate that might veto it.',
            ['class' => $class],
        );
    }

    /**
     * @param array<string, string> $context
     */
    private function reportOnce(string $key, string $message, array $context): void
    {
        if (\array_key_exists($key, $this->reported)) {
            return;
        }

        $this->reported[$key] = true;
        $this->logger?->warning($message, $context);
    }
}
