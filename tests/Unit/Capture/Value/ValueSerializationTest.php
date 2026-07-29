<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Capture\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Capture\DefaultLabelResolver;
use Th3Mouk\AuditTrail\Capture\EntityIdResolver;
use Th3Mouk\AuditTrail\Capture\Value\ChainValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\DateTimeValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\EntityReferenceValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\EnumValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\ScalarValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\StringableValueSerializer;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\FakeValueSerializer;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\StringableEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Enum\PostStatus;
use Th3Mouk\AuditTrail\Tests\Fixtures\Enum\PostVisibility;
use Th3Mouk\AuditTrail\Tests\Unit\Support\MappedEntitiesTestCase;

#[CoversClass(ChainValueSerializer::class)]
#[CoversClass(ScalarValueSerializer::class)]
#[CoversClass(DateTimeValueSerializer::class)]
#[CoversClass(EnumValueSerializer::class)]
#[CoversClass(StringableValueSerializer::class)]
#[CoversClass(EntityReferenceValueSerializer::class)]
final class ValueSerializationTest extends MappedEntitiesTestCase
{
    #[DataProvider('values')]
    public function testItMapsAValueToWhatTheTrailStores(mixed $value, mixed $expected): void
    {
        $chain = $this->chain();

        self::assertTrue($chain->supports($value));
        self::assertSame($expected, $chain->serialize($value));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function values(): iterable
    {
        yield 'a string' => ['Autumn', 'Autumn'];
        yield 'an empty string' => ['', ''];
        yield 'an integer' => [42, 42];
        yield 'a float' => [4.25, 4.25];
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'null, because emptying a column is a change' => [null, null];
        yield 'a date keeps its offset' => [
            new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('+02:00')),
            '2026-01-02T03:04:05+02:00',
        ];
        yield 'a date in UTC' => [
            new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC')),
            '2026-01-02T03:04:05+00:00',
        ];
        yield 'a backed enum stores the token the column holds' => [PostStatus::Published, 'published'];
        yield 'a pure enum stores its name' => [PostVisibility::Private, 'Private'];
    }

    public function testAnAssociationIsCapturedAsAReferenceCarryingItsLabel(): void
    {
        $post = new Post('Autumn');
        $this->mapEntity($post, 7);

        self::assertEquals($this->aRef(Post::class, 7, 'Autumn'), $this->chain()->serialize($post));
    }

    public function testAMappedEntityThatIsAlsoStringableIsStillCapturedAsAReference(): void
    {
        $entity = new StringableEntity('page');
        $this->mapEntity($entity, 3);

        self::assertEquals($this->aRef(StringableEntity::class, 3, 'Stringable page'), $this->chain()->serialize($entity));
    }

    public function testAValueObjectThatIsNotAMappedEntityIsFlattenedToItsOwnRendering(): void
    {
        self::assertSame('Stringable page', $this->chain()->serialize(new StringableEntity('page')));
    }

    #[DataProvider('unclaimedValues')]
    public function testAValueNothingClaimsIsExcludedRatherThanGuessedAt(mixed $value): void
    {
        $chain = $this->chain();

        self::assertFalse($chain->supports($value));
        self::assertNull($chain->serialize($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unclaimedValues(): iterable
    {
        yield 'a plain object' => [new \stdClass()];
        yield 'a closure' => [static fn (): null => null];
        yield 'a resource' => [\STDERR];
        yield 'an array, JSON column or not' => [['a', 'b']];
    }

    public function testAnExcludedTypeIsReportedOnceSoTheLogsStayReadable(): void
    {
        $chain = $this->chain();

        $chain->supports(new \stdClass());
        $chain->supports(new \stdClass());
        $chain->supports(static fn (): null => null);

        $reported = array_map(
            static fn (array $record): mixed => $record['context']['type'] ?? null,
            $this->logger->records('warning'),
        );

        self::assertSame(['stdClass', 'Closure'], $reported);
    }

    public function testAnApplicationSerializerRegisteredEarlierWins(): void
    {
        $chain = new ChainValueSerializer([
            FakeValueSerializer::forInstancesOf(PostStatus::class, static fn (mixed $value): string => 'claimed'),
            new EnumValueSerializer(),
        ]);

        self::assertSame('claimed', $chain->serialize(PostStatus::Published));
    }

    private function chain(): ChainValueSerializer
    {
        $auditableResolver = new AuditableResolver($this->doctrine());
        $labelResolver = new DefaultLabelResolver($auditableResolver);

        return new ChainValueSerializer([
            new ScalarValueSerializer(),
            new DateTimeValueSerializer(),
            new EnumValueSerializer(),
            new EntityReferenceValueSerializer($this->doctrine(), new EntityIdResolver($this->doctrine()), $labelResolver),
            new StringableValueSerializer(),
        ], $this->logger);
    }
}
