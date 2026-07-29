<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Entity\AuditLog;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Repository\AuditCursor;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * Paging a table that only ever grows, and only ever at the end.
 *
 * Offset paging is wrong here twice over: the page an offset points at drifts under the reader
 * as new entries arrive, and the deeper the page the more rows the server has to skip. The feed
 * pages by cursor instead — "give me what precedes this entry" — which is only meaningful if a
 * write between two page fetches cannot shift, duplicate or hide a row. That is what this test
 * arranges on purpose.
 */
#[CoversNothing]
final class KeysetFeedTest extends IntegrationTestCase
{
    private const int PAGE_SIZE = 2;

    public function testConsecutivePagesNeitherOverlapNorSkipAcrossAnInterleavedWrite(): void
    {
        $post = new Post('title-0');
        $this->given($post);
        $this->renameThrough($post, 'title-1', 'title-2', 'title-3', 'title-4', 'title-5');

        $firstPage = $this->page();
        self::assertSame(['title-5', 'title-4'], $this->titlesOf($firstPage));

        $this->renameThrough($post, 'title-6');

        $secondPage = $this->page($this->cursorAfter($firstPage));
        $thirdPage = $this->page($this->cursorAfter($secondPage));

        self::assertSame(['title-3', 'title-2'], $this->titlesOf($secondPage));
        self::assertSame(['title-1'], $this->titlesOf($thirdPage));

        $walked = [...$this->titlesOf($firstPage), ...$this->titlesOf($secondPage), ...$this->titlesOf($thirdPage)];

        self::assertSame(
            ['title-5', 'title-4', 'title-3', 'title-2', 'title-1'],
            $walked,
            'The walk must be the snapshot the first page was taken from, undisturbed by the later write.',
        );
        self::assertSame($walked, array_values(array_unique($walked)), 'No entry may appear on two pages.');
        self::assertNotContains('title-6', $walked, 'An entry written after the cursor belongs to a later feed, not to this walk.');
    }

    public function testAWalkThatReachesTheEndReturnsAnEmptyPage(): void
    {
        $post = new Post('title-0');
        $this->given($post);
        $this->renameThrough($post, 'title-1', 'title-2');

        $firstPage = $this->page();
        self::assertCount(2, $firstPage);

        self::assertSame([], $this->page($this->cursorAfter($firstPage)));
    }

    public function testTheNewestEntryOpensTheFeed(): void
    {
        $post = new Post('title-0');
        $this->given($post);
        $this->renameThrough($post, 'title-1', 'title-2', 'title-3');

        self::assertSame('title-3', $this->titlesOf($this->page())[0]);
    }

    public function testANewWriteBecomesTheHeadOfAFreshFeedWithoutTouchingTheCursoredWalk(): void
    {
        $post = new Post('title-0');
        $this->given($post);
        $this->renameThrough($post, 'title-1', 'title-2', 'title-3');

        $cursor = $this->cursorAfter($this->page());

        $this->renameThrough($post, 'title-4');

        self::assertSame('title-4', $this->titlesOf($this->page())[0]);
        self::assertSame(['title-1'], $this->titlesOf($this->page($cursor)));
    }

    /**
     * The case an identifier-only cursor gets wrong.
     *
     * A UUID v7 is chronological for entries the listener captured, and stops being so the moment
     * anything is backfilled: a historical timestamp under an identifier minted today. Ordering by
     * the identifier then disagrees with the feed's stated order, and a cursor built from one is
     * read against the other — which is how a page skips rows nobody deleted.
     */
    public function testABackfilledEntryIsWalkedInItsOwnChronologicalPlace(): void
    {
        $post = new Post('title-0');
        $this->given($post);
        $this->renameThrough($post, 'title-1', 'title-2');

        // Written last, dated first: exactly what importing an old system produces.
        $this->backfill('the-oldest-thing-that-ever-happened', '2019-01-01 08:00:00');

        $walk = [];

        for ($cursor = null; [] !== $page = $this->page($cursor); $cursor = $this->cursorAfter($page)) {
            $walk = [...$walk, ...$this->titlesOf($page)];
        }

        self::assertSame(
            ['title-2', 'title-1', 'the-oldest-thing-that-ever-happened'],
            $walk,
            'The walk is chronological, and the backfilled entry sits at its date rather than at its identifier.',
        );
    }

    /**
     * An entry dated in the past, written now — with an identifier minted now, like any other.
     */
    private function backfill(string $label, string $occurredAt): void
    {
        $this->em()->persist(AuditLog::fromEntry(new AuditEntry(
            action: AuditAction::Update,
            entityType: 'post',
            entityId: '4217',
            occurredAt: new \DateTimeImmutable($occurredAt, new \DateTimeZone('UTC')),
            entityLabel: $label,
            changes: ['title' => ['before' => null, 'after' => $label]],
        )));
        $this->em()->flush();
    }

    private function renameThrough(Post $post, string ...$titles): void
    {
        foreach ($titles as $title) {
            $post->rename($title);
            $this->em()->flush();
        }
    }

    /**
     * @return list<AuditLog>
     */
    private function page(?AuditCursor $before = null): array
    {
        return $this->logsOf(
            $this->logs()->createFeedQueryBuilder($before)->setMaxResults(self::PAGE_SIZE),
        );
    }

    /**
     * @param list<AuditLog> $page
     */
    private function cursorAfter(array $page): AuditCursor
    {
        self::assertNotSame([], $page, 'A cursor can only be taken from a non-empty page.');

        return AuditCursor::of($page[\count($page) - 1]);
    }

    /**
     * @param list<AuditLog> $page
     *
     * @return list<string>
     */
    private function titlesOf(array $page): array
    {
        return array_map(
            static function (AuditLog $log): string {
                $title = $log->getChanges()['title'] ?? null;

                return \is_array($title) && \is_string($title['after'] ?? null) ? $title['after'] : '';
            },
            $page,
        );
    }
}
