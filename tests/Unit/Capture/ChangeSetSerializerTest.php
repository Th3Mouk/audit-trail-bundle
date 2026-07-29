<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Capture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Capture\ChangeSetSerializer;
use Th3Mouk\AuditTrail\Capture\Value\ChainValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\DateTimeValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\EnumValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\ScalarValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\StringableValueSerializer;
use Th3Mouk\AuditTrail\Metadata\FieldPolicyResolver;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Enum\PostStatus;

#[CoversClass(ChangeSetSerializer::class)]
final class ChangeSetSerializerTest extends AuditTrailTestCase
{
    /**
     * @param array<string, array<int, mixed>>                       $changeSet
     * @param array<string, array{before: mixed, after: mixed}>|null $expected
     */
    #[DataProvider('changeSets')]
    public function testWhetherAChangeIsWorthARowAndWhatTheRowSays(array $changeSet, ?array $expected): void
    {
        self::assertSame($expected, $this->serializer()->serializeChangeSet(new Post('Autumn'), $changeSet));
    }

    /**
     * @return iterable<string, array{array<string, array<int, mixed>>, array<string, array{before: mixed, after: mixed}>|null}>
     */
    public static function changeSets(): iterable
    {
        yield 'nothing changed at all' => [[], null];

        yield 'only an opted-out column changed' => [
            ['internalNotes' => ['a note', 'another note']],
            null,
        ];

        yield 'several opted-out columns changed' => [
            ['internalNotes' => [null, 'a note']],
            null,
        ];

        yield 'only a masked column changed' => [
            ['secret' => ['hunter2', 'correct-horse']],
            ['secret' => ['before' => '********', 'after' => '********']],
        ];

        yield 'only a masked column with its own sentinel changed' => [
            ['apiKey' => ['live_1', 'live_2']],
            ['apiKey' => ['before' => '[redacted]', 'after' => '[redacted]']],
        ];

        yield 'a tracked column changed' => [
            ['views' => [0, 1]],
            ['views' => ['before' => 0, 'after' => 1]],
        ];

        yield 'a tracked column emptied' => [
            ['publishedAt' => [null, null]],
            ['publishedAt' => ['before' => null, 'after' => null]],
        ];

        yield 'tracked and opted-out together' => [
            ['title' => ['Autumn', 'Winter'], 'internalNotes' => ['a note', 'another note']],
            ['title' => ['before' => 'Autumn', 'after' => 'Winter']],
        ];

        yield 'tracked and masked together' => [
            ['title' => ['Autumn', 'Winter'], 'secret' => ['hunter2', 'correct-horse']],
            [
                'title' => ['before' => 'Autumn', 'after' => 'Winter'],
                'secret' => ['before' => '********', 'after' => '********'],
            ],
        ];

        yield 'an opted-out column between two kept ones' => [
            [
                'title' => ['Autumn', 'Winter'],
                'internalNotes' => ['a note', 'another note'],
                'apiKey' => ['live_1', 'live_2'],
            ],
            [
                'title' => ['before' => 'Autumn', 'after' => 'Winter'],
                'apiKey' => ['before' => '[redacted]', 'after' => '[redacted]'],
            ],
        ];

        yield 'a column no attribute mentions' => [
            ['status' => [PostStatus::Draft, PostStatus::Published]],
            ['status' => ['before' => 'draft', 'after' => 'published']],
        ];
    }

    public function testAMaskedValueIsNeitherReadNorEmitted(): void
    {
        $payload = $this->serializer()->serializeChangeSet(new Post('Autumn'), [
            'secret' => ['hunter2', 'correct-horse-battery'],
            'apiKey' => [self::unreadable(), self::unreadable()],
            'title' => ['Autumn', 'Winter'],
        ]);

        self::assertNotNull($payload);
        self::assertSame(['before' => '********', 'after' => '********'], $payload['secret']);
        self::assertSame(['before' => '[redacted]', 'after' => '[redacted]'], $payload['apiKey']);
        self::assertSame(['before' => 'Autumn', 'after' => 'Winter'], $payload['title']);

        $encoded = json_encode($payload, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('hunter2', $encoded);
        self::assertStringNotContainsString('correct-horse-battery', $encoded);
    }

    public function testAValueNothingCanRenderIsLeftOutOfTheDiff(): void
    {
        $payload = $this->serializer()->serializeChangeSet(new Post('Autumn'), [
            'title' => ['Autumn', 'Winter'],
            'author' => [new \stdClass(), new \stdClass()],
        ]);

        self::assertSame(['title' => ['before' => 'Autumn', 'after' => 'Winter']], $payload);
    }

    public function testSomethingDidHappenEvenWhenNoneOfItCouldBeRendered(): void
    {
        self::assertSame([], $this->serializer()->serializeChangeSet(new Post('Autumn'), [
            'author' => [new \stdClass(), null],
        ]));
    }

    public function testTheFullStateKeepsValuesFlatMasksSecretsAndDropsOptedOutColumns(): void
    {
        $state = $this->serializer()->serializeState(new Post('Autumn'), [
            'title' => 'Autumn',
            'views' => 12,
            'status' => PostStatus::Published,
            'publishedAt' => new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('+02:00')),
            'secret' => 'hunter2',
            'apiKey' => self::unreadable(),
            'internalNotes' => 'not for the trail',
        ]);

        self::assertSame([
            'title' => 'Autumn',
            'views' => 12,
            'status' => 'published',
            'publishedAt' => '2026-01-02T03:04:05+02:00',
            'secret' => '********',
            'apiKey' => '[redacted]',
        ], $state);
    }

    public function testTheFullStateLeavesOutWhatItCannotRender(): void
    {
        $state = $this->serializer()->serializeState(new Post('Autumn'), [
            'title' => 'Autumn',
            'author' => new \stdClass(),
        ]);

        self::assertSame(['title' => 'Autumn'], $state);
    }

    private function serializer(): ChangeSetSerializer
    {
        return new ChangeSetSerializer(
            new FieldPolicyResolver(),
            new ChainValueSerializer([
                new ScalarValueSerializer(),
                new DateTimeValueSerializer(),
                new EnumValueSerializer(),
                new StringableValueSerializer(),
            ], $this->logger),
        );
    }

    /**
     * A value that punishes anyone who looks at it, so "the mask is emitted without reading the
     * secret" is proven by the absence of an explosion rather than by trusting the code.
     */
    private static function unreadable(): \Stringable
    {
        return new class implements \Stringable {
            public function __toString(): string
            {
                throw new \LogicException('A masked value was read.');
            }
        };
    }
}
