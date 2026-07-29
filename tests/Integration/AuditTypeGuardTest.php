<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use Th3Mouk\AuditTrail\Metadata\AuditTypeResolver;
use Th3Mouk\AuditTrail\Metadata\AuditTypeWarmer;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\CompositeKeyEntity;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\DeepChild;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * The collision guard, wired and run against real Doctrine metadata.
 *
 * Whether it detects a collision is settled by its unit test. What only a container can settle is
 * that it is registered at all, that it received the audited entity manager, and that it survives
 * every mapped class in this kernel — including the audited composite-key entity, which capture
 * rejects at flush time and which a warmer must not promote into a boot failure.
 */
#[CoversClass(AuditTypeWarmer::class)]
final class AuditTypeGuardTest extends IntegrationTestCase
{
    public function testTheGuardIsRegisteredAndPassesOnRealMetadata(): void
    {
        self::assertContains(
            CompositeKeyEntity::class,
            $this->mappedClasses(),
            'This assertion is only meaningful while an audited composite-key entity is mapped here.',
        );

        $warmer = self::getContainer()->get(AuditTypeWarmer::class);
        $cacheDir = self::getContainer()->getParameter('kernel.cache_dir');

        self::assertInstanceOf(AuditTypeWarmer::class, $warmer);
        self::assertIsString($cacheDir);
        self::assertFalse($warmer->isOptional(), 'A collision must fail the build, not wait to be asked.');
        self::assertSame([], $warmer->warmUp($cacheDir));
    }

    public function testTheTypesThisKernelExposesAreTheDerivedOnes(): void
    {
        $resolver = self::getContainer()->get(AuditTypeResolver::class);

        self::assertInstanceOf(AuditTypeResolver::class, $resolver);
        self::assertSame('post', $resolver->typeOf(Post::class));
        self::assertSame('deep-child', $resolver->typeOf(DeepChild::class));
        self::assertSame('composite-key-entity', $resolver->typeOf(CompositeKeyEntity::class));
    }

    /**
     * @return list<class-string>
     */
    private function mappedClasses(): array
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return array_map(
            static fn (ClassMetadata $metadata): string => $metadata->getName(),
            $entityManager->getMetadataFactory()->getAllMetadata(),
        );
    }
}
