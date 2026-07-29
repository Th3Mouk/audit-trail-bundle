<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case;

use Gedmo\Translatable\TranslatableListener;
use Th3Mouk\AuditTrail\AuditLoggerInterface;
use Th3Mouk\AuditTrail\Bridge\Gedmo\TranslationAuditListener;
use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Capture\DefaultLabelResolver;
use Th3Mouk\AuditTrail\Capture\DefaultScopeResolver;
use Th3Mouk\AuditTrail\Capture\EntityIdResolver;
use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Metadata\FieldPolicyResolver;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Storage\FlushState;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailKernelTestCase;

/**
 * Skips the whole Gedmo bridge suite, loudly, when the library is not installed.
 *
 * The skip happens before the kernel boots, because the kernels this suite uses name Gedmo
 * classes in their wiring and would fail to compile rather than report a missing optional
 * dependency.
 */
abstract class GedmoBridgeTestCase extends AuditTrailKernelTestCase
{
    #[\Override]
    protected static function kernelUnderTest(): string
    {
        return GedmoBridgeKernel::class;
    }

    protected function setUp(): void
    {
        if (!GedmoBridgeKernel::isSupported()) {
            self::markTestSkipped(
                'gedmo/doctrine-extensions is not installed (Translatable, SoftDeleteable and Blameable listeners are all required): the Gedmo bridge suite cannot run.',
            );
        }

        parent::setUp();
    }

    /**
     * Entries produced by the translation bridge: it is the only capture path that puts a
     * locale in the metadata.
     *
     * @return list<AuditEntry>
     */
    protected function translationEntries(): array
    {
        return array_values(array_filter(
            $this->recordedEntries(),
            static fn (AuditEntry $entry): bool => \array_key_exists('locale', $entry->metadata),
        ));
    }

    /**
     * Entries produced by the ordinary capture listener: everything the translation bridge did
     * not write. Both land on the same class with the same `update` action, so the metadata is
     * the only thing that tells them apart.
     *
     * @return list<AuditEntry>
     */
    protected function ordinaryEntriesFor(string $entityClass): array
    {
        return array_values(array_filter(
            $this->recordedEntries(),
            static fn (AuditEntry $entry): bool => $entry->entityClass === $entityClass
                && !\array_key_exists('locale', $entry->metadata),
        ));
    }

    protected function assertNoTranslationEntryRecorded(): void
    {
        self::assertSame(
            [],
            $this->translationEntries(),
            'Expected no translated-content entry.',
        );
    }

    protected function assertOneTranslationEntry(string $entityClass, string $locale): AuditEntry
    {
        $matching = array_values(array_filter(
            $this->translationEntries(),
            static fn (AuditEntry $entry): bool => $entry->entityClass === $entityClass
                && ($entry->metadata['locale'] ?? null) === $locale,
        ));

        self::assertCount(
            1,
            $matching,
            \sprintf('Expected exactly one "%s" entry for %s.', $locale, $entityClass),
        );

        return $matching[0];
    }

    protected function countRows(string $entityClass): int
    {
        /** @var int|string $count */
        $count = $this->em()
            ->createQuery(\sprintf('SELECT COUNT(e.id) FROM %s e', $entityClass))
            ->getSingleScalarResult();

        return (int) $count;
    }

    protected function translatableListener(): TranslatableListener
    {
        $listener = $this->service(TranslatableListener::class);
        \assert($listener instanceof TranslatableListener);

        return $listener;
    }

    /**
     * The container's own flush state, not a fresh one: a listener assembled here that recorded
     * against a private copy would be telling the storage the opposite of what the flush is
     * doing, which is the one thing this collaborator exists to get right.
     */
    protected function flushState(): FlushState
    {
        $flushState = $this->service(FlushState::class);
        \assert($flushState instanceof FlushState);

        return $flushState;
    }

    /**
     * The bridge listener, assembled from the container's public seams.
     *
     * Its own service id is private, so a test that needs to call a public method on it —
     * `translationOnlyFields()`, the second call site the bridge offers `AuditLogListener` —
     * builds the collaborators the same way the service file does.
     */
    protected function translationAuditListener(): TranslationAuditListener
    {
        $entityManager = $this->em();
        $auditableResolver = new AuditableResolver($entityManager);
        $entityIdResolver = new EntityIdResolver($entityManager);
        $labelResolver = new DefaultLabelResolver($auditableResolver);

        $auditLogger = $this->service(AuditLoggerInterface::class);
        \assert($auditLogger instanceof AuditLoggerInterface);

        $valueSerializer = $this->service(ValueSerializerInterface::class);
        \assert($valueSerializer instanceof ValueSerializerInterface);

        $captureGate = $this->service(CaptureGateInterface::class);
        \assert($captureGate instanceof CaptureGateInterface);

        return new TranslationAuditListener(
            auditLogger: $auditLogger,
            auditableResolver: $auditableResolver,
            fieldPolicyResolver: new FieldPolicyResolver(),
            valueSerializer: $valueSerializer,
            entityIdResolver: $entityIdResolver,
            labelResolver: $labelResolver,
            scopeResolver: new DefaultScopeResolver($auditableResolver, $entityIdResolver, $labelResolver),
            captureGate: $captureGate,
            flushState: $this->flushState(),
            translatableListener: $this->translatableListener(),
        );
    }
}
