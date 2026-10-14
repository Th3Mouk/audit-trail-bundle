<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Id\IdentityGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\UnitOfWork;
use Th3Mouk\AuditTrail\Tests\Case\AuditTrailTestCase;

/**
 * A unit test case able to answer the few questions capture ever asks Doctrine:
 * is this class mapped, who assigns its key, and which identifier does the unit of work already know
 * for this object — or is the object still waiting on its INSERT for one.
 *
 * All of it is declared by the test itself, at any point before the behaviour runs, so a scenario
 * reads as "Doctrine knows this post as #7" without booting a kernel. Nothing here opens a
 * connection and no mapping richer than a single `id` column exists — column types, cascades
 * and the real change-set machinery belong to the integration suite.
 */
abstract class MappedEntitiesTestCase extends AuditTrailTestCase
{
    /** @var list<class-string> */
    private array $mappedClasses = [];

    /** @var list<class-string> */
    private array $compositeKeyedClasses = [];

    /** @var list<class-string> */
    private array $databaseKeyedClasses = [];

    /** @var array<int, string> */
    private array $knownIdentifiers = [];

    /** @var array<int, true> */
    private array $scheduledForInsert = [];

    /** @var array<int, true> */
    private array $keyless = [];

    private ?EntityManagerInterface $doctrine = null;

    protected function doctrine(): EntityManagerInterface
    {
        return $this->doctrine ??= $this->buildEntityManager();
    }

    /**
     * @param class-string $class
     */
    protected function mapClass(string $class): void
    {
        $this->mappedClasses[] = $class;
    }

    /**
     * @param class-string $class
     */
    protected function mapCompositeKeyedClass(string $class): void
    {
        $this->mappedClasses[] = $class;
        $this->compositeKeyedClasses[] = $class;
    }

    /**
     * @param class-string $class
     */
    protected function mapDatabaseKeyedClass(string $class): void
    {
        $this->mappedClasses[] = $class;
        $this->databaseKeyedClasses[] = $class;
    }

    protected function scheduleForInsert(object $entity): void
    {
        $this->scheduledForInsert[spl_object_id($entity)] = true;
    }

    /**
     * Scheduled for insertion and not yet known to the identity map, which is where a new entity
     * whose key the database assigns stays until its INSERT has run.
     */
    protected function mapEntityAwaitingItsKey(object $entity): void
    {
        $this->mapDatabaseKeyedClass($entity::class);
        $this->scheduleForInsert($entity);
        $this->keyless[spl_object_id($entity)] = true;
    }

    protected function mapEntity(object $entity, string|int $identifier): void
    {
        $this->mapClass($entity::class);
        $this->knownIdentifiers[spl_object_id($entity)] = (string) $identifier;
    }

    private function buildEntityManager(): EntityManagerInterface
    {
        $metadataFactory = $this->createStub(ClassMetadataFactory::class);
        $metadataFactory->method('isTransient')->willReturnCallback($this->isTransient(...));

        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('isInIdentityMap')->willReturnCallback(fn (object $entity): bool => !isset($this->keyless[spl_object_id($entity)]));
        $unitOfWork->method('isScheduledForInsert')->willReturnCallback(fn (object $entity): bool => isset($this->scheduledForInsert[spl_object_id($entity)]));
        $unitOfWork->method('isUninitializedObject')->willReturn(false);
        $unitOfWork->method('getEntityIdentifier')->willReturnCallback($this->identifierOf(...));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getMetadataFactory')->willReturn($metadataFactory);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $entityManager->method('getClassMetadata')->willReturnCallback($this->metadataOf(...));

        return $entityManager;
    }

    private function isTransient(string $class): bool
    {
        return !\in_array($class, $this->mappedClasses, true);
    }

    /**
     * @return array<string, string|null>
     */
    private function identifierOf(object $entity): array
    {
        return ['id' => $this->knownIdentifiers[spl_object_id($entity)] ?? null];
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadataOf(string $class): ClassMetadata
    {
        $composite = \in_array($class, $this->compositeKeyedClasses, true);

        $metadata = new ClassMetadata($class);
        $metadata->identifier = $composite ? ['tenant', 'reference'] : ['id'];
        $metadata->isIdentifierComposite = $composite;
        $metadata->setIdGenerator(\in_array($class, $this->databaseKeyedClasses, true) ? new IdentityGenerator() : new AssignedGenerator());

        return $metadata;
    }
}
