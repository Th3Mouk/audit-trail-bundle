<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Capture;

use PHPUnit\Framework\Attributes\CoversClass;
use Th3Mouk\AuditTrail\Capture\ChainActionResolver;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\FakeActionResolver;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * Not a unanimity chain, unlike the capture gates.
 *
 * Reclassifying an update is a positive claim about what happened, so the first resolver with an
 * opinion decides and the rest are never asked. An empty chain — which is every application that
 * installs no bridge — must leave the caller's own action standing.
 */
#[CoversClass(ChainActionResolver::class)]
final class ChainActionResolverTest extends AuditTrailTestCase
{
    public function testAnEmptyChainHasNoOpinion(): void
    {
        self::assertNull((new ChainActionResolver())->resolveAction(new Post('Autumn'), []));
    }

    public function testAbstentionsAreSkippedRatherThanTakenAsAnAnswer(): void
    {
        $abstaining = FakeActionResolver::abstaining();
        $answering = FakeActionResolver::answering(AuditAction::Delete);

        $action = (new ChainActionResolver([$abstaining, $answering]))->resolveAction(new Post('Autumn'), []);

        self::assertSame(AuditAction::Delete, $action);
        self::assertTrue($abstaining->wasAsked());
        self::assertTrue($answering->wasAsked());
    }

    public function testTheFirstAnswerWinsAndTheRestAreNeverConsulted(): void
    {
        $first = FakeActionResolver::answering(AuditAction::Delete);
        $second = FakeActionResolver::answering(AuditAction::Create);

        $action = (new ChainActionResolver([$first, $second]))->resolveAction(new Post('Autumn'), []);

        self::assertSame(AuditAction::Delete, $action);
        self::assertFalse($second->wasAsked(), 'A resolver behind one that already answered must not be asked.');
    }
}
