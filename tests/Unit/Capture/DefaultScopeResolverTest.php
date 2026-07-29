<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Capture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Capture\DefaultLabelResolver;
use Th3Mouk\AuditTrail\Capture\DefaultScopeResolver;
use Th3Mouk\AuditTrail\Capture\EntityIdResolver;
use Th3Mouk\AuditTrail\Exception\InvalidAuditScope;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Metadata\AuditTypeResolver;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Comment;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\DeepChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\EmptyScopePathEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\MismatchedScopeEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\ScopeProviderEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\StringableEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\UnreachableScopeEntity;
use Th3Mouk\AuditTrail\Tests\Unit\Support\CamelCaseRootedEntity;
use Th3Mouk\AuditTrail\Tests\Unit\Support\ExplicitlyTypedRootEntity;
use Th3Mouk\AuditTrail\Tests\Unit\Support\MappedEntitiesTestCase;
use Th3Mouk\AuditTrail\Tests\Unit\Support\SelfScopingEntity;

#[CoversClass(DefaultScopeResolver::class)]
final class DefaultScopeResolverTest extends MappedEntitiesTestCase
{
    public function testAChildOneHopAwayIsAnchoredToItsRootWithALabel(): void
    {
        $post = new Post('Autumn');
        $this->mapEntity($post, 7);

        self::assertEquals(
            $this->aRef(Post::class, 7, 'Autumn'),
            $this->resolver()->resolve(new Comment($post, 'Nice one')),
        );
    }

    public function testAChildTwoHopsAwayIsAnchoredToTheSameRoot(): void
    {
        $post = new Post('Autumn');
        $this->mapEntity($post, 7);

        self::assertEquals(
            $this->aRef(Post::class, 7, 'Autumn'),
            $this->resolver()->resolve(new DeepChild(new Comment($post, 'Nice one'), 'A note')),
        );
    }

    public function testAnEntityThatBelongsNowhereHasNoRoot(): void
    {
        self::assertNull($this->resolver()->resolve(new Post('Autumn')));
    }

    public function testAHopThatWasNeverAssignedEndsTheWalkInsteadOfLoadingIt(): void
    {
        self::assertNull($this->resolver()->resolve(self::commentWithoutPost()));
    }

    public function testAnUnassignedHopHalfWayThroughEndsTheWalk(): void
    {
        $comment = self::commentWithoutPost();
        $child = (new \ReflectionClass(DeepChild::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(DeepChild::class, 'comment'))->setValue($child, $comment);

        self::assertNull($this->resolver()->resolve($child));
    }

    public function testARootWhoseIdentifierIsNotKnownYetYieldsNoRoot(): void
    {
        self::assertNull($this->resolver()->resolve(new Comment(new Post('Autumn'), 'Nice one')));
    }

    public function testTheEntityHasTheLastWordOnItsOwnRoot(): void
    {
        $resolver = $this->resolver();
        $entity = SelfScopingEntity::rootedAtPost('9', 'Autumn');

        self::assertEquals($this->aRef(Post::class, 9, 'Autumn'), $resolver->resolve($entity));
        self::assertSame('post', $resolver->resolveType($entity));
    }

    public function testAnEntityThatDeclinesToNameARootHasNone(): void
    {
        $resolver = $this->resolver();

        self::assertNull($resolver->resolve(SelfScopingEntity::rootless()));
        self::assertNull($resolver->resolveType(SelfScopingEntity::rootless()));
        self::assertNull($resolver->resolve(new ScopeProviderEntity()));
    }

    #[DataProvider('mappingMistakes')]
    public function testAMappingMistakeIsReportedRatherThanGuessedAt(object $entity, string $messageFragment): void
    {
        $this->expectException(InvalidAuditScope::class);
        $this->expectExceptionMessage($messageFragment);

        $this->resolver()->resolve($entity);
    }

    /**
     * @return iterable<string, array{object, string}>
     */
    public static function mappingMistakes(): iterable
    {
        yield 'the path resolves to another class' => [
            new MismatchedScopeEntity(new Post('Autumn')),
            'resolves to',
        ];

        yield 'the first segment does not exist' => [
            new UnreachableScopeEntity(),
            'cannot be walked',
        ];

        yield 'no path at all' => [
            new EmptyScopePathEntity(),
            'empty path',
        ];
    }

    #[DataProvider('discriminators')]
    public function testTheStoredDiscriminatorIsShortAndRefactorSafe(object $entity, ?string $expected): void
    {
        self::assertSame($expected, $this->resolver()->resolveType($entity));
    }

    /**
     * @return iterable<string, array{object, string|null}>
     */
    public static function discriminators(): iterable
    {
        yield 'a single-word root class' => [new Comment(new Post('Autumn'), 'Nice one'), 'post'];
        yield 'a multi-word root class becomes kebab case' => [new CamelCaseRootedEntity(new StringableEntity('page')), 'stringable-entity'];
        yield 'a discriminator chosen by the mapping' => [new ExplicitlyTypedRootEntity(), 'landing_page'];
        yield 'a root the walk could not reach' => [self::commentWithoutPost(), 'post'];
        yield 'an entity that belongs nowhere' => [new Post('Autumn'), null];
    }

    private function resolver(): DefaultScopeResolver
    {
        $auditableResolver = new AuditableResolver($this->doctrine());

        return new DefaultScopeResolver(
            $auditableResolver,
            new EntityIdResolver($this->doctrine()),
            new DefaultLabelResolver($auditableResolver),
            new AuditTypeResolver(),
        );
    }

    private static function commentWithoutPost(): Comment
    {
        return (new \ReflectionClass(Comment::class))->newInstanceWithoutConstructor();
    }
}
