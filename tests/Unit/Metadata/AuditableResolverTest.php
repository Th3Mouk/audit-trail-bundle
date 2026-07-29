<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Exception\AuditableEntityNotSupported;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Comment;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\CompositeKeyEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\DeepChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\InheritedChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\OptedOutChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\PlainEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\StringableEntity;
use Th3Mouk\AuditTrail\Tests\Unit\Support\MappedEntitiesTestCase;

#[CoversClass(AuditableResolver::class)]
final class AuditableResolverTest extends MappedEntitiesTestCase
{
    /**
     * @param class-string $class
     */
    #[DataProvider('classes')]
    public function testAuditingIsOptInAndInherited(string $class, bool $expected): void
    {
        self::assertSame($expected, $this->resolver()->isAuditable($class));
    }

    /**
     * @return iterable<string, array{class-string, bool}>
     */
    public static function classes(): iterable
    {
        yield 'marked on the class itself' => [Post::class, true];
        yield 'marked on a parent' => [InheritedChild::class, true];
        yield 'marked on a parent and revoked by the child' => [OptedOutChild::class, false];
        yield 'never marked at all' => [PlainEntity::class, false];
    }

    public function testAnEntityWithACompositeIdentifierIsRefusedOutright(): void
    {
        $this->mapCompositeKeyedClass(CompositeKeyEntity::class);

        $this->expectException(AuditableEntityNotSupported::class);
        $this->expectExceptionMessage('composite identifier');

        $this->resolver()->isAuditable(CompositeKeyEntity::class);
    }

    public function testAClassAlreadyAcceptedIsNotInspectedAgain(): void
    {
        $this->mapClass(CompositeKeyEntity::class);
        $resolver = $this->resolver();

        self::assertTrue($resolver->isAuditable(CompositeKeyEntity::class));

        $this->mapCompositeKeyedClass(CompositeKeyEntity::class);

        self::assertTrue(
            $resolver->isAuditable(CompositeKeyEntity::class),
            'The mapping was re-read: a memoised answer cannot see the identifier change.',
        );
    }

    public function testTheDesignatedLabelMemberIsReadOnceAndKept(): void
    {
        $resolver = $this->resolver();

        self::assertSame($resolver->labelTargetFor(Post::class), $resolver->labelTargetFor(Post::class));
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('labelTargets')]
    public function testItFindsTheMemberDesignatedAsTheTitle(string $class, ?string $expected): void
    {
        self::assertSame($expected, $this->resolver()->labelTargetFor($class)?->getName());
    }

    /**
     * @return iterable<string, array{class-string, string|null}>
     */
    public static function labelTargets(): iterable
    {
        yield 'a designated property' => [Post::class, 'title'];
        yield 'a designated method' => [Comment::class, 'excerpt'];
        yield 'a property designated on a parent' => [InheritedChild::class, 'name'];
        yield 'nothing designated' => [PlainEntity::class, null];
        yield 'nothing designated, stringable' => [StringableEntity::class, null];
    }

    /**
     * @param class-string      $class
     * @param class-string|null $expectedRoot
     */
    #[DataProvider('scopes')]
    public function testItReadsWhereTheEntityBelongs(string $class, ?string $expectedRoot, ?string $expectedPath): void
    {
        $scope = $this->resolver()->scopeFor($class);

        self::assertSame($expectedRoot, $scope?->root);
        self::assertSame($expectedPath, $scope?->via);
    }

    /**
     * @return iterable<string, array{class-string, class-string|null, string|null}>
     */
    public static function scopes(): iterable
    {
        yield 'one hop away from its root' => [Comment::class, Post::class, 'post'];
        yield 'two hops away from its root' => [DeepChild::class, Post::class, 'comment.post'];
        yield 'a root in its own right' => [Post::class, null, null];
        yield 'audited by inheritance, unscoped' => [InheritedChild::class, null, null];
    }

    private function resolver(): AuditableResolver
    {
        return new AuditableResolver($this->doctrine());
    }
}
