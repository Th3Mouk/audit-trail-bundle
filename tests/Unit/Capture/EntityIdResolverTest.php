<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Capture;

use PHPUnit\Framework\Attributes\CoversClass;
use Th3Mouk\AuditTrail\Capture\EntityIdResolver;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Unit\Support\MappedEntitiesTestCase;

#[CoversClass(EntityIdResolver::class)]
final class EntityIdResolverTest extends MappedEntitiesTestCase
{
    public function testAnEntityWaitingOnItsInsertForAKeyHasNone(): void
    {
        $post = new Post('Autumn');
        $this->mapEntityAwaitingItsKey($post);

        self::assertTrue($this->resolver()->isPending($post));
        self::assertNull($this->resolver()->resolve($post));
    }

    public function testAnEntityWhoseKeyTheApplicationAssignsIsNeverWaitingForOne(): void
    {
        $post = new Post('Autumn');
        $this->mapEntity($post, 'known-before-the-insert');
        $this->scheduleForInsert($post);

        self::assertFalse($this->resolver()->isPending($post));
        self::assertSame('known-before-the-insert', $this->resolver()->resolve($post));
    }

    public function testAnEntityIsNoLongerWaitingOnceItsInsertHasGivenItAKey(): void
    {
        $post = new Post('Autumn');
        $this->mapDatabaseKeyedClass(Post::class);
        $this->mapEntity($post, 12);

        self::assertFalse($this->resolver()->isPending($post));
        self::assertSame('12', $this->resolver()->resolve($post));
    }

    public function testAnObjectDoctrineDoesNotMapIsNeverWaitingForAKey(): void
    {
        self::assertFalse($this->resolver()->isPending(new \stdClass()));
    }

    private function resolver(): EntityIdResolver
    {
        return new EntityIdResolver($this->doctrine());
    }
}
