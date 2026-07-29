<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform;

use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform\Case\AuditFeedTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Comment;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * The questions a reader actually asks of a trail.
 *
 * "What happened to this row", "what did this actor do", "what happened to this aggregate",
 * "what happened last week" — each one is a filter, and the aggregate question is the reason
 * there is no nested per-aggregate route.
 */
final class AuditFeedFilterTest extends AuditFeedTestCase
{
    private string $oldest;

    private string $middle;

    private string $newest;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->oldest, $this->middle, $this->newest] = $this->seed(
            $this->anEntry(
                action: AuditAction::Create,
                entityClass: Post::class,
                entityId: 1,
                actor: $this->anActor('7', 'human', 'Ada'),
                root: $this->aScopeRef('post', 1),
                occurredAt: $this->anInstant('2026-01-01 08:00:00'),
            ),
            $this->anEntry(
                action: AuditAction::Update,
                entityClass: Post::class,
                entityId: 2,
                actor: $this->anActor('9', 'service', 'Importer'),
                root: $this->aScopeRef('post', 2),
                occurredAt: $this->anInstant('2026-02-01 08:00:00'),
            ),
            $this->anEntry(
                action: AuditAction::Delete,
                entityClass: Comment::class,
                entityId: 3,
                root: $this->aScopeRef('post', 1),
                occurredAt: $this->anInstant('2026-03-01 08:00:00'),
            ),
        );
    }

    public function testTheEntityTypeFilterSelectsOneKindOfThing(): void
    {
        $this->assertFeedIs([$this->middle, $this->oldest], ['entityType' => 'post']);
        $this->assertFeedIs([$this->newest], ['entityType' => 'comment']);
    }

    public function testTheEntityIdentifierFilterSelectsOneRowsHistory(): void
    {
        $this->assertFeedIs([$this->middle], ['entityType' => 'post', 'entityId' => '2']);
    }

    /**
     * A class name is not a query parameter, and it is not a type either.
     *
     * `entityClass` is stored beside the type as information; nothing filters on it. API Platform
     * ignores a parameter it does not know, so asking for one returns the unfiltered feed — and
     * handing a class name to the parameter that replaced it matches nothing at all.
     */
    public function testAClassNameIsNoLongerPartOfTheQueryContract(): void
    {
        $this->assertFeedIs([$this->newest, $this->middle, $this->oldest], ['entityClass' => Post::class]);
        $this->assertFeedIs([], ['entityType' => Comment::class]);
    }

    public function testTheActionFilterSelectsOneKindOfChange(): void
    {
        $this->assertFeedIs([$this->newest], ['action' => AuditAction::Delete->value]);
        $this->assertFeedIs([$this->oldest], ['action' => AuditAction::Create->value]);
    }

    public function testTheActorFiltersSelectWhoActed(): void
    {
        $this->assertFeedIs([$this->middle], ['actorType' => 'service']);
        $this->assertFeedIs([$this->oldest], ['actorId' => '7']);
    }

    public function testFilteringOnTheRootReturnsExactlyThatAggregatesHistory(): void
    {
        $this->assertFeedIs([$this->newest, $this->oldest], ['rootType' => 'post', 'rootId' => '1']);
        $this->assertFeedIs([$this->middle], ['rootType' => 'post', 'rootId' => '2']);
    }

    public function testTheOccurredAtRangeSelectsAWindow(): void
    {
        $this->assertFeedIs(
            [$this->newest, $this->middle],
            ['occurredAt' => ['after' => '2026-02-01T00:00:00+00:00', 'before' => '2026-03-15T00:00:00+00:00']],
        );
        $this->assertFeedIs(
            [$this->oldest],
            ['occurredAt' => ['before' => '2026-01-15T00:00:00+00:00']],
        );
    }

    /**
     * The feed's order is fixed, and a client cannot change it.
     *
     * A keyset cursor is only meaningful against the sort key it was issued for, so honouring an
     * `order` parameter would hand back a differently-sorted collection whose `next` link then skips
     * and repeats entries. No order filter is registered; the parameter is simply not part of the
     * contract, and the feed stays newest-first whatever is asked of it. Reordering is what the
     * repository is for — see docs/reading-the-trail.md.
     */
    public function testTheFeedOrderIsFixedAndIgnoresAnOrderParameter(): void
    {
        $newestFirst = [$this->newest, $this->middle, $this->oldest];

        $this->assertFeedIs($newestFirst, []);
        $this->assertFeedIs($newestFirst, ['order' => ['occurredAt' => 'asc']]);
        $this->assertFeedIs($newestFirst, ['order' => ['id' => 'asc']]);
    }

    public function testAFilterThatMatchesNothingReturnsNothing(): void
    {
        $this->assertFeedIs([], ['entityType' => 'nowhere']);
    }

    /**
     * @param list<string>         $expected
     * @param array<string, mixed> $query
     */
    private function assertFeedIs(array $expected, array $query): void
    {
        self::assertSame(
            $expected,
            $this->idsOf($this->collection(self::uri(self::COLLECTION, $query))),
            \sprintf('Unexpected feed for %s', http_build_query($query)),
        );
    }
}
