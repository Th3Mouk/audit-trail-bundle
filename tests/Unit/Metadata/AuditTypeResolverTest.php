<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Th3Mouk\AuditTrail\Metadata\AuditTypeResolver;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\DeepChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Unit\Support\Collision\Legacy\SettledInvoice;
use Th3Mouk\AuditTrail\Tests\Unit\Support\TypedChildEntity;
use Th3Mouk\AuditTrail\Tests\Unit\Support\UntypedChildEntity;

#[CoversClass(AuditTypeResolver::class)]
final class AuditTypeResolverTest extends TestCase
{
    #[DataProvider('shortNames')]
    public function testAClassNameBecomesAKebabCaseType(string $class, string $expected): void
    {
        self::assertSame($expected, AuditTypeResolver::derive($class));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shortNames(): iterable
    {
        yield 'a single word' => ['App\Entity\Post', 'post'];
        yield 'two words' => ['App\Entity\OrganizationMembership', 'organization-membership'];
        yield 'an acronym at the front' => ['App\Entity\SSQPost', 'ssq-post'];
        yield 'an acronym in the middle' => ['App\Entity\HTTPRequestLog', 'http-request-log'];
        yield 'a trailing digit' => ['App\Entity\Address2', 'address2'];
        yield 'a class in the global namespace' => ['Invoice', 'invoice'];
    }

    public function testADeclaredTypeWins(): void
    {
        self::assertSame('legacy-invoice', new AuditTypeResolver()->typeOf(SettledInvoice::class));
    }

    public function testAnUndeclaredTypeIsDerivedFromTheClass(): void
    {
        $resolver = new AuditTypeResolver();

        self::assertSame('post', $resolver->typeOf(Post::class));
        self::assertSame('deep-child', $resolver->typeOf(DeepChild::class));
    }

    /**
     * A name is the one thing subclasses must not share, or their two histories become one.
     */
    public function testADeclaredTypeIsNotInherited(): void
    {
        $resolver = new AuditTypeResolver();

        self::assertSame('untyped-child-entity', $resolver->typeOf(UntypedChildEntity::class));
        self::assertSame('the-child', $resolver->typeOf(TypedChildEntity::class));
    }

    /**
     * Callers hold a class in application code and a type in a URL or a report; both are accepted so
     * neither has to know which it has.
     */
    public function testAStringThatIsAlreadyATypeIsLeftAlone(): void
    {
        $resolver = new AuditTypeResolver();

        self::assertSame('post', $resolver->typeOf('post'));
        self::assertSame('legacy-invoice', $resolver->typeOf('legacy-invoice'));
        self::assertSame('a type nobody declared', $resolver->typeOf('a type nobody declared'));
    }

    public function testTheAnswerIsMemoisedPerClass(): void
    {
        $resolver = new AuditTypeResolver();

        self::assertSame($resolver->typeOf(Post::class), $resolver->typeOf(Post::class));
    }
}
