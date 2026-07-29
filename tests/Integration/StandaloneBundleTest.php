<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use Gedmo\SoftDeleteable\SoftDeleteableListener;
use Gedmo\Translatable\TranslatableListener;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Th3Mouk\AuditTrail\Actor\ActorResolverInterface;
use Th3Mouk\AuditTrail\AuditLoggerInterface;
use Th3Mouk\AuditTrail\Capture\ActionResolverInterface;
use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Capture\FieldExclusionInterface;
use Th3Mouk\AuditTrail\Capture\LabelResolverInterface;
use Th3Mouk\AuditTrail\Capture\ScopeResolverInterface;
use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Storage\AuditStorageInterface;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;

/**
 * The strongest claim in the suite, and the cheapest to break by accident.
 *
 * `DoctrineOnlyKernel` registers FrameworkBundle, DoctrineBundle and AuditTrailBundle — nothing
 * else. No security, no API Platform, no Gedmo. The kernel *is* the proof: if the bundle ever
 * grows a hidden reference to any of them, the container stops compiling and every test in this
 * file fails at once, which is far louder than a README promising portability.
 *
 * The public aliases are asserted alongside, because a bundle nobody can extend is coupled in a
 * different way: replacing a default has to be autowiring, not a fork.
 */
#[CoversNothing]
final class StandaloneBundleTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function absentOptionalDependencies(): iterable
    {
        yield 'security token storage' => ['security.token_storage'];
        yield 'security authorization checker' => ['security.authorization_checker'];
        yield 'api platform resource name collection' => ['api_platform.metadata.resource.name_collection_factory'];
        yield 'api platform pagination' => ['api_platform.pagination'];
        yield 'gedmo translatable listener' => [TranslatableListener::class];
        yield 'gedmo soft deleteable listener' => [SoftDeleteableListener::class];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicExtensionPoints(): iterable
    {
        yield 'manual logger' => [AuditLoggerInterface::class];
        yield 'storage' => [AuditStorageInterface::class];
        yield 'label resolver' => [LabelResolverInterface::class];
        yield 'scope resolver' => [ScopeResolverInterface::class];
        yield 'actor resolver' => [ActorResolverInterface::class];
        yield 'value serializer' => [ValueSerializerInterface::class];
        yield 'capture gate' => [CaptureGateInterface::class];
        yield 'action resolver' => [ActionResolverInterface::class];
        yield 'field exclusion' => [FieldExclusionInterface::class];
    }

    public function testTheKernelRegistersNothingButFrameworkDoctrineAndTheBundle(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        self::assertSame(
            ['FrameworkBundle', 'DoctrineBundle', 'AuditTrailBundle'],
            array_keys($kernel->getBundles()),
        );
    }

    #[DataProvider('absentOptionalDependencies')]
    public function testTheContainerCompilesWithoutMentioningAnyOptionalDependency(string $serviceId): void
    {
        self::assertFalse(
            self::getContainer()->has($serviceId),
            \sprintf('The bundle must not pull "%s" into a container that never asked for it.', $serviceId),
        );
    }

    #[DataProvider('publicExtensionPoints')]
    public function testEveryReplaceableCollaboratorIsReachableByItsInterface(string $serviceId): void
    {
        self::assertTrue(
            self::getContainer()->has($serviceId),
            \sprintf('"%s" must be autowirable so an application can decorate it.', $serviceId),
        );
    }

    public function testCaptureWorksInThatContainer(): void
    {
        $post = new Post('Analytical Engine');
        $this->save($post);

        $created = $this->assertOneEntry(AuditAction::Create, Post::class);
        $this->assertLabelIs($created, 'Analytical Engine');

        $this->forgetRecordedEntries();

        $post->rename('The Analytical Engine');
        $this->em()->flush();

        $updated = $this->assertOneEntry(AuditAction::Update, Post::class);
        $this->assertFieldChanged($updated, 'title', 'Analytical Engine', 'The Analytical Engine');

        $this->forgetRecordedEntries();

        $this->remove($post);

        $this->assertOneEntry(AuditAction::Delete, Post::class);
    }

    public function testTheTrailLivesInTheTableTheApplicationNames(): void
    {
        $this->rebootWith(['table_name' => 'application_history']);

        self::assertSame('application_history', $this->auditTable());

        $this->save(new Post('Analytical Engine'));

        self::assertSame(1, $this->countRowsIn('application_history'));
        $this->assertOneEntry(AuditAction::Create, Post::class);
    }

    public function testTheGlobalKillSwitchSilencesCaptureEntirely(): void
    {
        $this->rebootWith(['enabled' => false]);

        $post = new Post('Analytical Engine');
        $this->save($post);

        $post->rename('The Analytical Engine');
        $this->em()->flush();

        $this->remove($post);

        $this->assertNothingRecorded();
        self::assertSame(0, $this->countAuditRows());
    }
}
