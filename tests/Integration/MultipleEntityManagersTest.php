<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use Th3Mouk\AuditTrail\Enum\AuditAction;
use Th3Mouk\AuditTrail\Tests\Fixtures\Entity\Post;
use Th3Mouk\AuditTrail\Tests\Fixtures\Kernel\TwoEntityManagersKernel;

/**
 * One entity manager owns the trail; the others are not audited.
 *
 * Doctrine puts an event listener on every connection unless its tag names one, so the capture
 * listener is invoked for a flush of any manager. Acting on that would enlist the entry in a
 * UnitOfWork other than the one that produced the change — outside its transaction, so a rollback
 * would no longer take the entry with it, and the bundle's one guarantee would be gone.
 *
 * The same entity classes are mapped in both managers on purpose: the class cannot be what tells
 * them apart, so only the manager the change was flushed through can.
 */
#[CoversNothing]
final class MultipleEntityManagersTest extends IntegrationTestCase
{
    #[\Override]
    protected static function kernelUnderTest(): string
    {
        return TwoEntityManagersKernel::class;
    }

    public function testAChangeFlushedThroughTheAuditedManagerIsRecorded(): void
    {
        $post = new Post('Analytical Engine');

        $this->save($post);

        $this->assertOneEntry(AuditAction::Create, Post::class);
    }

    public function testAChangeFlushedThroughAnotherManagerIsNotRecorded(): void
    {
        $unaudited = $this->managerNamed(TwoEntityManagersKernel::UNAUDITED_MANAGER);
        self::assertNotSame($this->em(), $unaudited, 'The fixture must really provide two managers.');

        $post = new Post('Difference Engine');
        $unaudited->persist($post);
        $unaudited->flush();

        $this->assertNothingRecorded();
        self::assertSame(0, $this->countAuditRows());

        // The change itself must still be persisted: the guard skips capture, never the write.
        self::assertNotNull($post->getId());
    }

    private function managerNamed(string $name): EntityManagerInterface
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $manager = $registry->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
