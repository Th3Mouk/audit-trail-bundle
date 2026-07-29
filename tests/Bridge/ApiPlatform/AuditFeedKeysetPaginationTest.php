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

        self::assertSame(
            [$oldest],
            $this->idsOf($this->collection(self::uri(self::COLLECTION, ['id' => ['lt' => $middle]]))),
        );
        self::assertSame(
            [$newest],
            $this->idsOf($this->collection(self::uri(self::COLLECTION, ['id' => ['gt' => $middle]]))),
        );
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
