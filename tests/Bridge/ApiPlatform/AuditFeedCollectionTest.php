<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform;

use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform\Case\AuditFeedTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * The feed itself: what comes back, how much of it, and how much of each entry.
 */
final class AuditFeedCollectionTest extends AuditFeedTestCase
{
    private const array SMALL_PAGES = [
        'bridges' => ['api_platform' => ['items_per_page' => 3, 'max_items_per_page' => 5]],
    ];

    public function testTheCollectionReturnsTheTrailNewestFirst(): void
    {
        $identifiers = $this->seedEntries(3);

        self::assertSame(array_reverse($identifiers), $this->idsOf($this->collection()));
    }

    public function testAnEmptyTrailIsAnEmptyCollection(): void
    {
        self::assertSame([], $this->idsOf($this->collection()));
    }

    public function testTheConfiguredPageSizeIsHonoured(): void
    {
        $this->bootWith(self::SMALL_PAGES);
        $this->seedEntries(8);

        self::assertCount(3, $this->membersOf($this->collection()));
    }

    public function testAClientMayAskForAnotherPageSize(): void
    {
        $this->bootWith(self::SMALL_PAGES);
        $this->seedEntries(8);

        $payload = $this->collection(self::uri(self::COLLECTION, ['itemsPerPage' => 5]));

        self::assertCount(5, $this->membersOf($payload));
    }

    public function testAClientCannotAskForMoreThanTheConfiguredMaximum(): void
    {
        $this->bootWith(self::SMALL_PAGES);
        $this->seedEntries(8);

        $payload = $this->collection(self::uri(self::COLLECTION, ['itemsPerPage' => 100]));

        self::assertCount(5, $this->membersOf($payload));
    }

    public function testTheCollectionOmitsTheHeavyFields(): void
    {
        $this->seed($this->aRichEntry());

        $member = $this->membersOf($this->collection())[0];

        self::assertArrayNotHasKey('changes', $member);
        self::assertArrayNotHasKey('metadata', $member);
        self::assertArrayNotHasKey('ip', $member);
        self::assertArrayNotHasKey('requestId', $member);

        self::assertSame(Post::class, $member['entityClass'] ?? null);
        self::assertSame(AuditAction::Update->value, $member['action'] ?? null);
        self::assertSame('A post', $member['entityLabel'] ?? null);
    }

    public function testTheItemCarriesTheHeavyFields(): void
    {
        $this->seed($this->aRichEntry());

        $iri = $this->membersOf($this->collection())[0]['@id'] ?? null;
        self::assertIsString($iri);

        $payload = $this->payloadOf($this->getAsReader($iri.'.jsonld'));

        self::assertSame(['title' => ['before' => 'Before', 'after' => 'After']], $payload['changes'] ?? null);
        self::assertSame(['source' => 'bridge-test'], $payload['metadata'] ?? null);
        self::assertSame('203.0.113.7', $payload['ip'] ?? null);
        self::assertSame('request-42', $payload['requestId'] ?? null);
    }

    private function aRichEntry(): AuditEntry
    {
        return $this->anEntry(
            entityClass: Post::class,
            entityId: 17,
            changes: ['title' => ['before' => 'Before', 'after' => 'After']],
            entityLabel: 'A post',
            requestId: 'request-42',
            ip: '203.0.113.7',
            metadata: ['source' => 'bridge-test'],
        );
    }
}
