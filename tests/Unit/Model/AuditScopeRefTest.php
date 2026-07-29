<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Model\AuditScopeRef;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;

#[CoversClass(AuditScopeRef::class)]
final class AuditScopeRefTest extends AuditTrailTestCase
{
    #[DataProvider('roots')]
    public function testAnAggregateIsIdentifiedByAShortTypeAndAStringKey(string|int $id, string $expected): void
    {
        $root = AuditScopeRef::of('questionnaire', $id, 'Autumn campaign');

        self::assertSame('questionnaire', $root->type);
        self::assertSame($expected, $root->id);
        self::assertSame('Autumn campaign', $root->label);
    }

    /**
     * @return iterable<string, array{string|int, string}>
     */
    public static function roots(): iterable
    {
        yield 'a numeric key' => [7, '7'];
        yield 'a uuid key' => ['0198c0de-cafe-7000-8000-000000000001', '0198c0de-cafe-7000-8000-000000000001'];
    }

    public function testAnUnlabelledAggregateIsStillAValidAnchor(): void
    {
        self::assertNull(AuditScopeRef::of('questionnaire', 7)->label);
    }
}
