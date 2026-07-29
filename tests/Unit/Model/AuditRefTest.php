<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Model\AuditRef;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

#[CoversClass(AuditRef::class)]
final class AuditRefTest extends AuditTrailTestCase
{
    #[DataProvider('references')]
    public function testAnIdentifierOfAnyKindIsKeptAsAString(string|int $id, string $expected): void
    {
        self::assertSame($expected, AuditRef::of(Post::class, $id)->id);
    }

    /**
     * @return iterable<string, array{string|int, string}>
     */
    public static function references(): iterable
    {
        yield 'a numeric key' => [7, '7'];
        yield 'a numeric key already given as a string' => ['7', '7'];
        yield 'a uuid key' => ['0198c0de-cafe-7000-8000-000000000001', '0198c0de-cafe-7000-8000-000000000001'];
    }

    public function testAReferenceWithoutALabelIsStillAValidReference(): void
    {
        self::assertNull(AuditRef::of(Post::class, 7)->label);
    }

    public function testItSerialisesToTheShapeStorageKeeps(): void
    {
        self::assertSame(
            ['class' => Post::class, 'id' => '7', 'label' => 'Autumn'],
            AuditRef::of(Post::class, 7, 'Autumn')->jsonSerialize(),
        );
    }

    public function testAStoredReferenceReadsBackIdentically(): void
    {
        $reference = AuditRef::of(Post::class, 7, 'Autumn');

        self::assertEquals($reference, AuditRef::fromArray($reference->jsonSerialize()));
    }

    /**
     * @param array{class?: string, id?: string|int, label?: string|null} $stored
     */
    #[DataProvider('storedShapes')]
    public function testAReferenceReadBackFromAnOlderRowDoesNotBlowUp(array $stored, string $expectedClass, string $expectedId, ?string $expectedLabel): void
    {
        $reference = AuditRef::fromArray($stored);

        self::assertSame($expectedClass, $reference->class);
        self::assertSame($expectedId, $reference->id);
        self::assertSame($expectedLabel, $reference->label);
    }

    /**
     * @return iterable<string, array{array{class?: string, id?: string|int, label?: string|null}, string, string, string|null}>
     */
    public static function storedShapes(): iterable
    {
        yield 'a complete row' => [['class' => Post::class, 'id' => '7', 'label' => 'Autumn'], Post::class, '7', 'Autumn'];
        yield 'a row written before labels existed' => [['class' => Post::class, 'id' => '7'], Post::class, '7', null];
        yield 'a row with an explicit null label' => [['class' => Post::class, 'id' => '7', 'label' => null], Post::class, '7', null];
        yield 'a row whose identifier was stored as a number' => [['class' => Post::class, 'id' => 7], Post::class, '7', null];
        yield 'an empty row' => [[], '', '', null];
    }
}
