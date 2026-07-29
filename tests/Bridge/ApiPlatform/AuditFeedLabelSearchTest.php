<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform;

use Th3Mouk\AuditTrail\Model\Actor;
use Th3Mouk\AuditTrail\Model\AuditScopeRef;
use Th3Mouk\AuditTrail\Tests\Bridge\ApiPlatform\Case\AuditFeedTestCase;

/**
 * Searching the trail the way a person would: by name, not by identifier.
 *
 * Identifiers are what a machine looks entries up by. Someone answering "what happened to Jean
 * Dupont?" has a name, and the snapshotted labels are the only place a name survives once the row it
 * came from has been renamed or deleted — which is exactly when the question gets asked.
 *
 * `requestId` is the other half of the same idea, from the other direction: one correlation
 * identifier returns every change a single call made.
 */
final class AuditFeedLabelSearchTest extends AuditFeedTestCase
{
    public function testTheActorLabelIsSearchedCaseInsensitivelyAndPartially(): void
    {
        $byAlice = $this->seed($this->anEntry(entityId: 1, actor: Actor::of('88', 'operator', 'Alice Martin')));
        $this->seed($this->anEntry(entityId: 2, actor: Actor::of('91', 'operator', 'Bob Dupont')));

        self::assertSame($byAlice, $this->feedFor(['actorLabel' => 'alice']));
        self::assertSame($byAlice, $this->feedFor(['actorLabel' => 'MARTIN']));
    }

    public function testTheEntityLabelIsSearchable(): void
    {
        $expected = $this->seed($this->anEntry(entityId: 1, entityLabel: 'Manager — Jean Dupont'));
        $this->seed($this->anEntry(entityId: 2, entityLabel: 'Viewer — Ada Lovelace'));

        self::assertSame($expected, $this->feedFor(['entityLabel' => 'jean']));
    }

    public function testTheRootLabelIsSearchable(): void
    {
        $expected = $this->seed($this->anEntry(entityId: 1, root: AuditScopeRef::of('organization', 31, 'Acme')));
        $this->seed($this->anEntry(entityId: 2, root: AuditScopeRef::of('organization', 32, 'Globex')));

        self::assertSame($expected, $this->feedFor(['rootLabel' => 'acm']));
    }

    public function testEveryChangeOfOneRequestComesBackFromItsCorrelationIdentifier(): void
    {
        $sameCall = $this->seed(
            $this->anEntry(entityId: 1, requestId: 'req-abc'),
            $this->anEntry(entityId: 2, requestId: 'req-abc'),
        );
        $this->seed($this->anEntry(entityId: 3, requestId: 'req-zzz'));

        self::assertSame(array_reverse($sameCall), $this->feedFor(['requestId' => 'req-abc']));
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return list<string>
     */
    private function feedFor(array $query): array
    {
        return $this->idsOf($this->collection(self::uri(self::COLLECTION, $query)));
    }
}
