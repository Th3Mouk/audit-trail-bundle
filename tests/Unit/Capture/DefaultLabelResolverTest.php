<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Capture;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Capture\DefaultLabelResolver;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Comment;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\InheritedChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\PlainEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\StringableEntity;
use Th3Mouk\AuditTrail\Tests\Unit\Support\MappedEntitiesTestCase;

#[CoversClass(DefaultLabelResolver::class)]
final class DefaultLabelResolverTest extends MappedEntitiesTestCase
{
    #[DataProvider('entities')]
    public function testItSnapshotsTheHumanTitleOfAnEntity(object $entity, ?string $expected): void
    {
        self::assertSame($expected, $this->resolver()->resolve($entity));
    }

    /**
     * @return iterable<string, array{object, string|null}>
     */
    public static function entities(): iterable
    {
        yield 'a designated property' => [new Post('Autumn'), 'Autumn'];
        yield 'a designated property inherited from a parent' => [new InheritedChild('Ada'), 'Ada'];
        yield 'a designated method' => [
            new Comment(new Post('Autumn'), 'A very long message that carries on'),
            'A very long message ',
        ];
        yield 'nothing designated, but stringable' => [new StringableEntity('page'), 'Stringable page'];
        yield 'nothing designated and not stringable' => [new PlainEntity('plain'), null];
        yield 'a designated property that is empty' => [new Post(''), null];
        yield 'a designated property never assigned' => [self::postWithoutTitle(), null];
    }

    public function testAnUnassignedTitleYieldsNothingWithoutPoisoningLaterReads(): void
    {
        $entity = self::postWithoutTitle();

        self::assertNull($this->resolver()->resolve($entity));

        $entity->rename('Autumn');

        self::assertSame('Autumn', $this->resolver()->resolve($entity));
    }

    private function resolver(): DefaultLabelResolver
    {
        return new DefaultLabelResolver(new AuditableResolver($this->doctrine()));
    }

    private static function postWithoutTitle(): Post
    {
        return (new \ReflectionClass(Post::class))->newInstanceWithoutConstructor();
    }
}
