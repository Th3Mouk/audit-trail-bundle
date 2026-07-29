<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Actor;

use PHPUnit\Framework\Attributes\CoversClass;
use Th3Mouk\AuditTrail\Actor\ChainActorResolver;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\FakeActorResolver;

#[CoversClass(ChainActorResolver::class)]
final class ChainActorResolverTest extends AuditTrailTestCase
{
    public function testTheFirstResolverThatClaimsTheChangeWins(): void
    {
        $expected = $this->anActor('7', 'operator', 'Jean Dupont');

        $chain = new ChainActorResolver([
            FakeActorResolver::deferring(),
            FakeActorResolver::returning($expected),
            FakeActorResolver::returning($this->anActor('9', 'operator', 'Someone else')),
        ]);

        self::assertSame($expected, $chain->resolve());
    }

    public function testAChangeNobodyClaimsIsStillRecordedButAttributedToNobody(): void
    {
        $chain = new ChainActorResolver([FakeActorResolver::deferring(), FakeActorResolver::deferring()]);

        self::assertFalse($chain->resolve()->isKnown());
    }

    public function testAnApplicationWithNoResolverAtAllAttributesToNobody(): void
    {
        self::assertFalse((new ChainActorResolver())->resolve()->isKnown());
    }

    public function testNothingIsRememberedBetweenTwoResolutions(): void
    {
        $resolver = FakeActorResolver::returning($first = $this->anActor('1', 'worker', 'First message'));
        $chain = new ChainActorResolver([$resolver]);

        self::assertSame($first, $chain->resolve());

        $resolver->will($second = $this->anActor('2', 'worker', 'Second message'));

        self::assertSame($second, $chain->resolve());
    }
}
