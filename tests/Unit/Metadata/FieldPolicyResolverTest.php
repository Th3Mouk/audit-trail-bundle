<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Exception\ConflictingFieldPolicy;
use Th3Mouk\AuditTrail\Metadata\FieldPolicy;
use Th3Mouk\AuditTrail\Metadata\FieldPolicyResolver;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\ConflictingPolicyEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\InheritedChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Unit\Support\PolicyInheritingChild;

#[CoversClass(FieldPolicyResolver::class)]
#[CoversClass(FieldPolicy::class)]
final class FieldPolicyResolverTest extends AuditTrailTestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('properties')]
    public function testItDecidesHowEachPropertyParticipates(string $class, string $property, FieldPolicy $expected): void
    {
        self::assertSame($expected, (new FieldPolicyResolver())->policyFor($class, $property));
    }

    /**
     * @return iterable<string, array{class-string, string, FieldPolicy}>
     */
    public static function properties(): iterable
    {
        yield 'an annotated title is tracked' => [Post::class, 'title', FieldPolicy::Tracked];
        yield 'a column nobody said anything about is tracked' => [Post::class, 'views', FieldPolicy::Tracked];
        yield 'a masked column is masked' => [Post::class, 'secret', FieldPolicy::Masked];
        yield 'a masked column with its own sentinel is masked' => [Post::class, 'apiKey', FieldPolicy::Masked];
        yield 'an opted-out column is ignored' => [Post::class, 'internalNotes', FieldPolicy::Ignored];
        yield 'a field the class does not declare is tracked' => [Post::class, 'thereIsNoSuchProperty', FieldPolicy::Tracked];
        yield 'an inherited property keeps its mask' => [PolicyInheritingChild::class, 'token', FieldPolicy::Masked];
        yield 'an inherited property keeps its opt-out' => [PolicyInheritingChild::class, 'trace', FieldPolicy::Ignored];
        yield 'an inherited plain property is tracked' => [PolicyInheritingChild::class, 'subject', FieldPolicy::Tracked];
        yield 'an inherited labelled property is tracked' => [InheritedChild::class, 'name', FieldPolicy::Tracked];
    }

    #[DataProvider('sentinels')]
    public function testItSaysWhichSentinelStandsInForAValue(string $property, string $expected): void
    {
        self::assertSame($expected, (new FieldPolicyResolver())->maskFor(Post::class, $property));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sentinels(): iterable
    {
        yield 'the global default' => ['secret', '********'];
        yield 'the per-property override' => ['apiKey', '[redacted]'];
    }

    public function testTheGlobalSentinelIsConfigurableAndAPropertyStillOverridesIt(): void
    {
        $resolver = new FieldPolicyResolver('###');

        self::assertSame('###', $resolver->maskFor(Post::class, 'secret'));
        self::assertSame('[redacted]', $resolver->maskFor(Post::class, 'apiKey'));
    }

    public function testAPropertyCannotBeBothDroppedAndMasked(): void
    {
        $this->expectException(ConflictingFieldPolicy::class);
        $this->expectExceptionMessage('ConflictingPolicyEntity::$token');

        (new FieldPolicyResolver())->policyFor(ConflictingPolicyEntity::class, 'token');
    }

    #[DataProvider('rowTriggering')]
    public function testOnlyAnIgnoredPolicyKeepsAnEntryFromBeingWritten(FieldPolicy $policy, bool $expected): void
    {
        self::assertSame($expected, $policy->isRowTriggering());
    }

    /**
     * @return iterable<string, array{FieldPolicy, bool}>
     */
    public static function rowTriggering(): iterable
    {
        yield 'tracked' => [FieldPolicy::Tracked, true];
        yield 'masked' => [FieldPolicy::Masked, true];
        yield 'ignored' => [FieldPolicy::Ignored, false];
    }

    #[DataProvider('valueRecording')]
    public function testOnlyATrackedPolicyKeepsTheValue(FieldPolicy $policy, bool $expected): void
    {
        self::assertSame($expected, $policy->recordsValue());
    }

    /**
     * @return iterable<string, array{FieldPolicy, bool}>
     */
    public static function valueRecording(): iterable
    {
        yield 'tracked' => [FieldPolicy::Tracked, true];
        yield 'masked' => [FieldPolicy::Masked, false];
        yield 'ignored' => [FieldPolicy::Ignored, false];
    }
}
