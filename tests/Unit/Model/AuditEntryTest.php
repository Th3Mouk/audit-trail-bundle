<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\Actor;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

#[CoversClass(AuditEntry::class)]
final class AuditEntryTest extends AuditTrailTestCase
{
    public function testAnEntryDescribesOneFactAndNothingMore(): void
    {
        $occurredAt = $this->anInstant();
        $actor = $this->anActor();
        $root = $this->aScopeRef('post', 7, 'Autumn');

        $entry = new AuditEntry(
            AuditAction::Update,
            'post',
            '7',
            $occurredAt,
            Post::class,
            $actor,
            'Autumn',
            ['title' => $this->aChange('Autumn', 'Winter')],
            $root,
            'req-4711',
            '203.0.113.7',
            ['reason' => 'gdpr'],
        );

        self::assertSame(AuditAction::Update, $entry->action);
        self::assertSame('post', $entry->entityType);
        self::assertSame(Post::class, $entry->entityClass);
        self::assertSame('7', $entry->entityId);
        self::assertSame($occurredAt, $entry->occurredAt);
        self::assertSame($actor, $entry->actor);
        self::assertSame('Autumn', $entry->entityLabel);
        self::assertSame(['title' => ['before' => 'Autumn', 'after' => 'Winter']], $entry->changes);
        self::assertSame($root, $entry->root);
        self::assertSame('req-4711', $entry->requestId);
        self::assertSame('203.0.113.7', $entry->ip);
        self::assertSame(['reason' => 'gdpr'], $entry->metadata);
    }

    public function testAnEntryWithNothingButTheFactIsValid(): void
    {
        $entry = new AuditEntry(AuditAction::Delete, 'post', '7', $this->anInstant());

        self::assertNull($entry->entityClass, 'An entry can describe something that has no PHP class.');
        self::assertNull($entry->actor);
        self::assertNull($entry->entityLabel);
        self::assertNull($entry->changes);
        self::assertNull($entry->root);
        self::assertNull($entry->requestId);
        self::assertNull($entry->ip);
        self::assertSame([], $entry->metadata);
    }

    public function testMetadataIsAddedWithoutTouchingTheEntryItCameFrom(): void
    {
        $entry = $this->anEntry(metadata: ['reason' => 'gdpr', 'ticket' => 'PPD-1']);

        $enriched = $entry->withMetadata(['ticket' => 'PPD-2', 'batch' => 'nightly']);

        self::assertSame(['reason' => 'gdpr', 'ticket' => 'PPD-2', 'batch' => 'nightly'], $enriched->metadata);
        self::assertSame(['reason' => 'gdpr', 'ticket' => 'PPD-1'], $entry->metadata);
    }

    public function testTheActorIsReplacedWithoutDisturbingAnythingElse(): void
    {
        $entry = $this->anEntry(
            changes: ['title' => $this->aChange('Autumn', 'Winter')],
            entityLabel: 'Autumn',
            actor: $this->anActor('7', 'operator', 'Jean Dupont'),
            root: $this->aScopeRef('post', 7),
            requestId: 'req-4711',
            ip: '203.0.113.7',
            metadata: ['reason' => 'gdpr'],
        );
        $operator = Actor::of('import-2026-01', 'batch', 'Nightly import');

        $reattributed = $entry->withActor($operator);

        self::assertSame($operator, $reattributed->actor);
        self::assertSame($entry->action, $reattributed->action);
        self::assertSame($entry->entityClass, $reattributed->entityClass);
        self::assertSame($entry->entityId, $reattributed->entityId);
        self::assertSame($entry->occurredAt, $reattributed->occurredAt);
        self::assertSame($entry->entityLabel, $reattributed->entityLabel);
        self::assertSame($entry->changes, $reattributed->changes);
        self::assertSame($entry->root, $reattributed->root);
        self::assertSame($entry->requestId, $reattributed->requestId);
        self::assertSame($entry->ip, $reattributed->ip);
        self::assertSame($entry->metadata, $reattributed->metadata);
    }

    public function testAnEntryCanBeStrippedOfItsActor(): void
    {
        $entry = $this->anEntry(actor: $this->anActor());

        self::assertNull($entry->withActor(null)->actor);
        self::assertNotNull($entry->actor);
    }
}
