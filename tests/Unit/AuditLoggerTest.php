<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Actor\ActorResolverInterface;
use Th3Mouk\AuditTrail\Actor\ChainActorResolver;
use Th3Mouk\AuditTrail\AuditLogger;
use Th3Mouk\AuditTrail\AuditLoggerInterface;
use Th3Mouk\AuditTrail\Context\RequestContext;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\FakeActorResolver;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

#[CoversClass(AuditLogger::class)]
final class AuditLoggerTest extends AuditTrailTestCase
{
    private RequestContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new RequestContext();
    }

    /**
     * @param \Closure(AuditLoggerInterface): void $record
     * @param array<string, mixed>|null            $expectedChanges
     */
    #[DataProvider('recordings')]
    public function testEachMethodBuildsTheEntryItPromises(\Closure $record, AuditAction $action, ?array $expectedChanges): void
    {
        $record($this->auditLogger());

        $entry = $this->assertOneEntry($action, Post::class, entityId: 7);

        self::assertSame($expectedChanges, $entry->changes);
    }

    /**
     * @param \Closure(AuditLoggerInterface): void $record
     * @param array<string, mixed>|null            $expectedChanges
     */
    #[DataProvider('recordings')]
    public function testTheKillSwitchSilencesEveryMethod(\Closure $record, AuditAction $action, ?array $expectedChanges): void
    {
        $record($this->auditLogger(enabled: false));

        $this->assertNothingRecorded();
    }

    /**
     * @return iterable<string, array{\Closure(AuditLoggerInterface): void, AuditAction, array<string, mixed>|null}>
     */
    public static function recordings(): iterable
    {
        yield 'a creation carries the full state' => [
            static function (AuditLoggerInterface $logger): void {
                $logger->created(Post::class, 7, ['title' => 'Autumn']);
            },
            AuditAction::Create,
            ['title' => 'Autumn'],
        ];

        yield 'a creation with nothing to say carries no payload' => [
            static function (AuditLoggerInterface $logger): void {
                $logger->created(Post::class, 7, []);
            },
            AuditAction::Create,
            null,
        ];

        yield 'an update carries a before and an after' => [
            static function (AuditLoggerInterface $logger): void {
                $logger->updated(Post::class, 7, ['title' => ['before' => 'Autumn', 'after' => 'Winter']]);
            },
            AuditAction::Update,
            ['title' => ['before' => 'Autumn', 'after' => 'Winter']],
        ];

        yield 'a deletion carries the state it had' => [
            static function (AuditLoggerInterface $logger): void {
                $logger->deleted(Post::class, 7, ['title' => 'Autumn']);
            },
            AuditAction::Delete,
            ['title' => 'Autumn'],
        ];

        yield 'a deletion nobody described carries no payload' => [
            static function (AuditLoggerInterface $logger): void {
                $logger->deleted(Post::class, 7);
            },
            AuditAction::Delete,
            null,
        ];

        yield 'an explicit record keeps the payload verbatim' => [
            static function (AuditLoggerInterface $logger): void {
                $logger->record(AuditAction::Update, Post::class, '7', ['views' => 12]);
            },
            AuditAction::Update,
            ['views' => 12],
        ];
    }

    public function testAnIdentifierOfAnyKindIsStoredAsAString(): void
    {
        $this->auditLogger()->created(Post::class, 7, ['title' => 'Autumn']);

        self::assertSame('7', $this->latestRecordedEntry()->entityId);
    }

    public function testTheAmbientContextAndAUtcTimestampTravelWithEveryEntry(): void
    {
        $this->context->setRequestId('req-4711');
        $this->context->setClientIp('203.0.113.7');

        $this->auditLogger()->updated(Post::class, 7, ['views' => ['before' => 1, 'after' => 2]]);

        $entry = $this->latestRecordedEntry();

        self::assertSame('req-4711', $entry->requestId);
        self::assertSame('203.0.113.7', $entry->ip);
        self::assertSame('UTC', $entry->occurredAt->getTimezone()->getName());
    }

    public function testTheLabelTheRootAndTheMetadataAreCarriedThrough(): void
    {
        $root = $this->aScopeRef('post', 7, 'Autumn');

        $this->auditLogger()->deleted(Post::class, 7, ['title' => 'Autumn'], 'Autumn', $root, ['reason' => 'gdpr']);

        $entry = $this->latestRecordedEntry();

        self::assertSame('Autumn', $entry->entityLabel);
        self::assertSame($root, $entry->root);
        self::assertSame(['reason' => 'gdpr'], $entry->metadata);
    }

    public function testTheActorComesFromTheResolverChain(): void
    {
        $expected = $this->anActor('7', 'operator', 'Jean Dupont');

        $this->auditLogger(actorResolver: new ChainActorResolver([FakeActorResolver::returning($expected)]))
            ->created(Post::class, 7, ['title' => 'Autumn']);

        self::assertSame($expected, $this->latestRecordedEntry()->actor);
    }

    public function testAChangeNobodyClaimsIsRecordedWithoutAnAttributableActor(): void
    {
        $this->auditLogger(actorResolver: FakeActorResolver::deferring())->created(Post::class, 7, ['title' => 'Autumn']);

        $this->assertNoKnownActor($this->latestRecordedEntry());
    }

    public function testWithActorAttributesEverythingItRecordsToOnePrincipal(): void
    {
        $resolved = $this->anActor('7', 'operator', 'Jean Dupont');
        $operator = $this->anActor('import-2026-01', 'batch', 'Nightly import');
        $logger = $this->auditLogger(actorResolver: new ChainActorResolver([FakeActorResolver::returning($resolved)]));

        $logger->withActor($operator)->created(Post::class, 7, ['title' => 'Autumn']);

        self::assertSame($operator, $this->latestRecordedEntry()->actor);
    }

    public function testWithActorLeavesTheLoggerItWasDerivedFromAlone(): void
    {
        $resolved = $this->anActor('7', 'operator', 'Jean Dupont');
        $logger = $this->auditLogger(actorResolver: new ChainActorResolver([FakeActorResolver::returning($resolved)]));

        $logger->withActor($this->anActor('import-2026-01', 'batch', 'Nightly import'));
        $logger->created(Post::class, 7, ['title' => 'Autumn']);

        self::assertSame($resolved, $this->latestRecordedEntry()->actor);
    }

    private function auditLogger(bool $enabled = true, ?ActorResolverInterface $actorResolver = null): AuditLogger
    {
        return new AuditLogger(
            $this->storage,
            $actorResolver ?? new ChainActorResolver([FakeActorResolver::returning($this->anActor())]),
            $this->context,
            $enabled,
        );
    }

    private function latestRecordedEntry(): AuditEntry
    {
        $entry = $this->storage->latest();

        self::assertNotNull($entry, 'Nothing was recorded.');

        return $entry;
    }
}
