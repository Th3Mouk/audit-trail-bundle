<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Exception\InvalidActor;
use Th3Mouk\AuditTrail\Model\Actor;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;

#[CoversClass(Actor::class)]
final class ActorTest extends AuditTrailTestCase
{
    /**
     * The bundle ships no taxonomy of principals, so any word an application uses for one is
     * a valid type — including words no library could have anticipated.
     */
    #[DataProvider('principals')]
    public function testAnyPrincipalAnApplicationHasIsAcceptable(string|int|null $id, ?string $type, ?string $expectedId): void
    {
        $actor = Actor::of($id, $type, 'Whoever this is');

        self::assertSame($expectedId, $actor->id);
        self::assertSame($type, $actor->type);
        self::assertSame('Whoever this is', $actor->label);
    }

    /**
     * @return iterable<string, array{string|int|null, string|null, string|null}>
     */
    public static function principals(): iterable
    {
        yield 'a numeric key becomes a string' => [42, 'user', '42'];
        yield 'a uuid key' => ['0198c0de-cafe-7000-8000-000000000001', 'user', '0198c0de-cafe-7000-8000-000000000001'];
        yield 'a machine identity' => ['billing-worker', 'service_account', 'billing-worker'];
        yield 'a word no library invented' => ['nightly', 'wombat-wrangler', 'nightly'];
        yield 'no type at all' => ['42', null, '42'];
        yield 'no identifier at all' => [null, 'system', null];
    }

    public function testNoPrincipalAtAllIsALegitimateAnswer(): void
    {
        $actor = Actor::unknown();

        self::assertNull($actor->id);
        self::assertNull($actor->type);
        self::assertNull($actor->label);
        self::assertFalse($actor->isKnown());
    }

    #[DataProvider('attributions')]
    public function testAnActorIsAttributableAsSoonAsAnythingIsKnownAboutIt(Actor $actor, bool $expected): void
    {
        self::assertSame($expected, $actor->isKnown());
    }

    /**
     * @return iterable<string, array{Actor, bool}>
     */
    public static function attributions(): iterable
    {
        yield 'nothing is known' => [Actor::unknown(), false];
        yield 'an identifier alone' => [Actor::of('42'), true];
        yield 'a type alone' => [new Actor(type: 'system'), true];
        yield 'a label alone' => [new Actor(label: 'Nightly import'), true];
        yield 'everything' => [new Actor('42', 'user', 'Jean Dupont'), true];
    }

    #[DataProvider('overlongFields')]
    public function testAPrincipalThatCannotBeStoredIsRefusedAtTheDoor(?string $id, ?string $type, string $messageFragment): void
    {
        $this->expectException(InvalidActor::class);
        $this->expectExceptionMessage($messageFragment);

        new Actor($id, $type);
    }

    /**
     * @return iterable<string, array{string|null, string|null, string}>
     */
    public static function overlongFields(): iterable
    {
        yield 'a type one character too long' => [
            null,
            str_repeat('t', Actor::MAX_TYPE_LENGTH + 1),
            'Actor type',
        ];

        yield 'an identifier one character too long' => [
            str_repeat('i', Actor::MAX_ID_LENGTH + 1),
            null,
            'Actor identifier',
        ];
    }

    public function testTheLongestStorablePrincipalIsAccepted(): void
    {
        $actor = new Actor(str_repeat('i', Actor::MAX_ID_LENGTH), str_repeat('t', Actor::MAX_TYPE_LENGTH));

        self::assertSame(Actor::MAX_ID_LENGTH, \strlen((string) $actor->id));
        self::assertSame(Actor::MAX_TYPE_LENGTH, \strlen((string) $actor->type));
    }
}
