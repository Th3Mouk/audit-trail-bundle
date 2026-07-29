<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Model\AuditRef;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;

#[CoversClass(AuditRef::class)]
final class AuditRefTest extends AuditTrailTestCase
{
    #[DataProvider('references')]
    public function testAnIdentifierOfAnyKindIsKeptAsAString(string|int $id, string $expected): void
    {
        self::assertSame($expected, AuditRef::of('post', $id)->id);
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
        self::assertNull(AuditRef::of('post', 7)->label);
    }

    public function testItSerialisesToTheShapeStorageKeeps(): void
    {
        self::assertSame(
            ['type' => 'post', 'id' => '7', 'label' => 'Autumn'],
            AuditRef::of('post', 7, 'Autumn')->jsonSerialize(),
        );
    }

    public function testAStoredReferenceReadsBackIdentically(): void
    {
        $reference = AuditRef::of('post', 7, 'Autumn');

        self::assertEquals($reference, AuditRef::fromArray($reference->jsonSerialize()));
    }

    /**
     * @param array{type?: string, id?: string|int, label?: string|null} $stored
     */
    #[DataProvider('storedShapes')]
    public function testAReferenceReadBackFromAnOlderRowDoesNotBlowUp(array $stored, string $expectedType, string $expectedId, ?string $expectedLabel): void
    {
        $reference = AuditRef::fromArray($stored);

        self::assertSame($expectedType, $reference->type);
        self::assertSame($expectedId, $reference->id);
        self::assertSame($expectedLabel, $reference->label);
    }

    /**
     * @return iterable<string, array{array{type?: string, id?: string|int, label?: string|null}, string, string, string|null}>
     */
    public static function storedShapes(): iterable
    {
        yield 'a complete row' => [['type' => 'post', 'id' => '7', 'label' => 'Autumn'], 'post', '7', 'Autumn'];
        yield 'a row written before labels existed' => [['type' => 'post', 'id' => '7'], 'post', '7', null];
        yield 'a row with an explicit null label' => [['type' => 'post', 'id' => '7', 'label' => null], 'post', '7', null];
        yield 'a row whose identifier was stored as a number' => [['type' => 'post', 'id' => 7], 'post', '7', null];
        yield 'an empty row' => [[], '', '', null];
    }
}
