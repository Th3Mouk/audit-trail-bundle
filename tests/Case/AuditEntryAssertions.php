<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Case;

use PHPUnit\Framework\Assert;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\AuditEntry;

/**
 * A vocabulary for saying what the trail should read like.
 *
 * The assertions work against whatever `recordedEntries()` returns, which is what lets the
 * same sentence describe a unit capture caught in memory and an integration capture read
 * back out of the database.
 *
 * Values are normalised before comparison, so an expectation may be written with the model
 * objects capture produces (an AuditRef) or with the array shape storage keeps — they mean
 * the same thing and both pass.
 */
trait AuditEntryAssertions
{
    /**
     * @return list<AuditEntry>
     */
    abstract protected function recordedEntries(): array;

    protected function assertNothingRecorded(): void
    {
        Assert::assertSame(
            [],
            $this->recordedEntries(),
            \sprintf("Expected an empty trail, found:\n%s", $this->describeRecordedEntries()),
        );
    }

    protected function assertRecordedCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->recordedEntries(),
            \sprintf("Unexpected number of entries. Recorded:\n%s", $this->describeRecordedEntries()),
        );
    }

    /**
     * @param array<string, mixed> $changes a subset: keys absent from the expectation are not asserted
     */
    protected function assertOneEntry(
        AuditAction $action,
        string $entityClass,
        array $changes = [],
        string|int|null $entityId = null,
    ): AuditEntry {
        $matching = $this->entriesMatching($action, $entityClass, $entityId);

        Assert::assertCount(
            1,
            $matching,
            \sprintf(
                "Expected exactly one %s entry for %s%s. Recorded:\n%s",
                $action->value,
                $entityClass,
                null === $entityId ? '' : \sprintf(' #%s', $entityId),
                $this->describeRecordedEntries(),
            ),
        );

        $entry = $matching[0];

        foreach ($changes as $field => $expected) {
            $this->assertRecordedChange($entry, $field, $expected);
        }

        return $entry;
    }

    /**
     * @return list<AuditEntry>
     */
    protected function assertEntriesRecorded(AuditAction $action, string $entityClass, int $expectedCount): array
    {
        $matching = $this->entriesMatching($action, $entityClass);

        Assert::assertCount(
            $expectedCount,
            $matching,
            \sprintf(
                "Expected %d %s entries for %s. Recorded:\n%s",
                $expectedCount,
                $action->value,
                $entityClass,
                $this->describeRecordedEntries(),
            ),
        );

        return $matching;
    }

    protected function assertNoEntryFor(string $entityClass): void
    {
        Assert::assertSame(
            [],
            $this->entriesFor($entityClass),
            \sprintf("Expected no entry for %s. Recorded:\n%s", $entityClass, $this->describeRecordedEntries()),
        );
    }

    protected function assertFieldChanged(AuditEntry $entry, string $field, mixed $before, mixed $after): void
    {
        $this->assertRecordedChange($entry, $field, ['before' => $before, 'after' => $after]);
    }

    protected function assertFieldMasked(AuditEntry $entry, string $field, string $mask = '********'): void
    {
        $this->assertRecordedChange($entry, $field, ['before' => $mask, 'after' => $mask]);
    }

    protected function assertFieldRecorded(AuditEntry $entry, string $field, mixed $value): void
    {
        $this->assertRecordedChange($entry, $field, $value);
    }

    protected function assertFieldNotRecorded(AuditEntry $entry, string $field): void
    {
        Assert::assertArrayNotHasKey(
            $field,
            $entry->changes ?? [],
            \sprintf('Field "%s" should not have been recorded: %s', $field, $this->describeEntry($entry)),
        );
    }

    /**
     * Order-insensitive: which fields were recorded is a contract, the order Doctrine happens
     * to hand them over in is not.
     *
     * @param list<string> $fields
     */
    protected function assertRecordedFieldsAre(AuditEntry $entry, array $fields): void
    {
        $expected = $fields;
        $actual = array_keys($entry->changes ?? []);
        sort($expected);
        sort($actual);

        Assert::assertSame(
            $expected,
            $actual,
            \sprintf('Unexpected recorded fields: %s', $this->describeEntry($entry)),
        );
    }

    protected function assertRootIs(AuditEntry $entry, string $type, string|int $id, ?string $label = null): void
    {
        Assert::assertNotNull($entry->root, \sprintf('Expected an aggregate root: %s', $this->describeEntry($entry)));
        Assert::assertSame($type, $entry->root->type);
        Assert::assertSame((string) $id, $entry->root->id);
        Assert::assertSame($label, $entry->root->label);
    }

    protected function assertNoRoot(AuditEntry $entry): void
    {
        Assert::assertNull($entry->root, \sprintf('Expected no aggregate root: %s', $this->describeEntry($entry)));
    }

    protected function assertActorIs(AuditEntry $entry, ?string $id, ?string $type = null, ?string $label = null): void
    {
        Assert::assertNotNull($entry->actor, \sprintf('Expected an actor: %s', $this->describeEntry($entry)));
        Assert::assertSame($id, $entry->actor->id);
        Assert::assertSame($type, $entry->actor->type);
        Assert::assertSame($label, $entry->actor->label);
    }

    protected function assertNoKnownActor(AuditEntry $entry): void
    {
        Assert::assertTrue(
            null === $entry->actor || !$entry->actor->isKnown(),
            \sprintf('Expected no attributable actor: %s', $this->describeEntry($entry)),
        );
    }

    protected function assertLabelIs(AuditEntry $entry, ?string $label): void
    {
        Assert::assertSame(
            $label,
            $entry->entityLabel,
            \sprintf('Unexpected entity label: %s', $this->describeEntry($entry)),
        );
    }

    /**
     * @return list<AuditEntry>
     */
    protected function entriesFor(string $entityClass): array
    {
        return array_values(array_filter(
            $this->recordedEntries(),
            static fn (AuditEntry $entry): bool => $entry->entityClass === $entityClass,
        ));
    }

    /**
     * @return list<AuditEntry>
     */
    protected function entriesMatching(AuditAction $action, string $entityClass, string|int|null $entityId = null): array
    {
        return array_values(array_filter(
            $this->recordedEntries(),
            static fn (AuditEntry $entry): bool => $entry->action === $action
                && $entry->entityClass === $entityClass
                && (null === $entityId || $entry->entityId === (string) $entityId),
        ));
    }

    protected function latestEntry(): ?AuditEntry
    {
        $entries = $this->recordedEntries();

        return [] === $entries ? null : $entries[\count($entries) - 1];
    }

    private function assertRecordedChange(AuditEntry $entry, string $field, mixed $expected): void
    {
        $changes = $entry->changes ?? [];

        Assert::assertArrayHasKey(
            $field,
            $changes,
            \sprintf('Field "%s" was not recorded: %s', $field, $this->describeEntry($entry)),
        );

        $actual = self::normalise($changes[$field]);
        $expected = self::normalise($expected);
        $message = \sprintf('Unexpected value recorded for "%s": %s', $field, $this->describeEntry($entry));

        if (\is_array($expected) || \is_array($actual)) {
            Assert::assertEquals($expected, $actual, $message);

            return;
        }

        Assert::assertSame($expected, $actual, $message);
    }

    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return self::normalise($value->jsonSerialize());
        }

        if (\is_array($value)) {
            return array_map(self::normalise(...), $value);
        }

        return $value;
    }

    private function describeRecordedEntries(): string
    {
        $entries = $this->recordedEntries();

        if ([] === $entries) {
            return '  (nothing)';
        }

        return implode("\n", array_map(
            fn (AuditEntry $entry): string => '  '.$this->describeEntry($entry),
            $entries,
        ));
    }

    private function describeEntry(AuditEntry $entry): string
    {
        return \sprintf(
            '%s %s#%s label=%s root=%s changes=%s',
            $entry->action->value,
            $entry->entityClass,
            $entry->entityId,
            $entry->entityLabel ?? '-',
            null === $entry->root ? '-' : \sprintf('%s#%s', $entry->root->type, $entry->root->id),
            json_encode(self::normalise($entry->changes), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR),
        );
    }
}
