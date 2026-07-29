<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform;

use Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform\Case\AuditFeedTestCase;

/**
 * Walking a growing trail without losing or repeating an entry.
 *
 * Offset paging drifts as soon as rows are inserted while a client is paging; the keyset cursor
 * is what makes the walk immune to it, and API Platform's own RangeFilter cannot express that
 * cursor on a UUID key — hence the bridge's own filter, exercised here through the URLs the feed
 * itself advertises.
 */
final class AuditFeedKeysetPaginationTest extends AuditFeedTestCase
{
    private const array SMALL_PAGES = [
        'bridges' => ['api_platform' => ['items_per_page' => 3, 'max_items_per_page' => 5]],
    ];

    private const int PAGE_LIMIT = 20;

    public function testFollowingTheCursorWalksTheWholeTrailWithoutDuplicatesOrGaps(): void
    {
        $this->bootWith(self::SMALL_PAGES);
        $identifiers = $this->seedEntries(7);

        self::assertSame(array_reverse($identifiers), $this->walkFrom(self::COLLECTION));
    }

    public function testAnEntryInsertedBetweenTwoRequestsDisturbsNeitherThePagesNorTheirOrder(): void
    {
        $this->bootWith(self::SMALL_PAGES);
        $identifiers = $this->seedEntries(5);

        $firstPage = $this->collection();
        $firstPageIds = $this->idsOf($firstPage);
        self::assertCount(3, $firstPageIds);

        $inserted = $this->seedEntries(1);

        $next = $this->nextUriOf($firstPage);
        self::assertIsString($next, 'A non-empty page must advertise the cursor of the next one.');

        $remaining = $this->walkFrom($next);

        self::assertSame(array_reverse($identifiers), [...$firstPageIds, ...$remaining]);
        self::assertNotContains($inserted[0], $remaining);
    }

    public function testTheCursorNarrowsTheFeedInBothDirections(): void
    {
        [$oldest, $middle, $newest] = $this->seedEntries(3);
        $instant = $this->anInstant()->format(\DateTimeInterface::ATOM);

        self::assertSame(
            [$oldest],
            $this->idsOf($this->collection(self::uri(self::COLLECTION, [
                'occurredAt' => ['lt' => $instant],
                'id' => ['lt' => $middle],
            ]))),
        );
        self::assertSame(
            [$newest],
            $this->idsOf($this->collection(self::uri(self::COLLECTION, [
                'occurredAt' => ['gt' => $instant],
                'id' => ['gt' => $middle],
            ]))),
        );
    }

    /**
     * A cursor is the whole sort key or nothing.
     *
     * Honouring half of it would apply `id < :cursor` while the feed is ordered by
     * `(occurredAt, id)` — the combination that drops older entries carrying larger identifiers. So a
     * lone half is ignored and the client gets the unfiltered feed rather than a subtly wrong page.
     */
    public function testHalfACursorIsIgnoredRatherThanAppliedOnItsOwn(): void
    {
        $identifiers = $this->seedEntries(3);

        self::assertSame(
            array_reverse($identifiers),
            $this->idsOf($this->collection(self::uri(self::COLLECTION, ['id' => ['lt' => $identifiers[2]]]))),
        );
        self::assertSame(
            array_reverse($identifiers),
            $this->idsOf($this->collection(self::uri(self::COLLECTION, [
                'occurredAt' => ['lt' => $this->anInstant()->format(\DateTimeInterface::ATOM)],
            ]))),
        );
    }

    /**
     * Walking stays correct when the timestamps disagree with the identifier order.
     *
     * This is the backfill: historical entries are seeded under fresh UUID v7 values, so the oldest
     * event carries the largest identifier. A cursor on the identifier alone would walk the feed in
     * the wrong order and either skip or repeat entries; a lexicographic cursor over
     * `(occurredAt, id)` cannot.
     */
    public function testWalkingIsStableWhenTimestampsDisagreeWithIdentifierOrder(): void
    {
        $this->bootWith(self::SMALL_PAGES);

        // Seeded newest-first, so identifiers ascend exactly as the instants descend.
        $expected = $this->seed(
            $this->anEntry(entityId: 1, occurredAt: $this->anInstant('2026-04-04 00:00:00')),
            $this->anEntry(entityId: 2, occurredAt: $this->anInstant('2026-03-03 00:00:00')),
            $this->anEntry(entityId: 3, occurredAt: $this->anInstant('2026-02-02 00:00:00')),
            $this->anEntry(entityId: 4, occurredAt: $this->anInstant('2026-01-01 00:00:00')),
        );

        $firstPage = $this->collection();
        $walked = $this->idsOf($firstPage);
        $next = $this->nextUriOf($firstPage);
        self::assertIsString($next);

        $walked = [...$walked, ...$this->walkFrom($next)];

        self::assertSame($expected, $walked, 'The feed must come back newest-first, once each.');
        self::assertSame($walked, array_values(array_unique($walked)), 'No entry may appear twice.');
    }

    public function testAnUnparseableCursorIsIgnoredInsteadOfEmptyingTheFeed(): void
    {
        $identifiers = $this->seedEntries(3);

        $payload = $this->collection(self::uri(self::COLLECTION, ['id' => ['lt' => 'not-a-uuid']]));

        self::assertSame(array_reverse($identifiers), $this->idsOf($payload));
    }

    public function testEntriesSharingATimestampAreStillTotallyOrdered(): void
    {
        $sameInstant = $this->anInstant('2026-05-05 12:00:00');
        $identifiers = $this->seed(...array_map(
            fn (int $number) => $this->anEntry(entityId: $number, occurredAt: $sameInstant),
            range(1, 4),
        ));

        $payload = $this->collection(self::uri(self::COLLECTION, ['order' => ['occurredAt' => 'desc']]));

        self::assertSame(array_reverse($identifiers), $this->idsOf($payload));
    }

    /**
     * @return list<string>
     */
    private function walkFrom(string $uri): array
    {
        $walked = [];

        for ($page = 1; $page <= self::PAGE_LIMIT; ++$page) {
            $payload = $this->collection($uri);
            $pageIds = $this->idsOf($payload);

            if ([] === $pageIds) {
                return $walked;
            }

            $walked = [...$walked, ...$pageIds];

            $next = $this->nextUriOf($payload);
            self::assertIsString($next, 'A non-empty page must advertise the cursor of the next one.');

            $uri = $next;
        }

        self::fail(\sprintf('The feed did not run out of pages within %d requests.', self::PAGE_LIMIT));
    }
}
