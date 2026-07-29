<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Builds the test schema straight from entity metadata.
 *
 * No migrations, no SQL fixtures: whatever the mapped entities say is what the database
 * gets, so a mapping change can never silently drift away from the tables tests run on.
 */
final readonly class SchemaBuilder
{
    public static function create(EntityManagerInterface $em): void
    {
        new SchemaTool($em)->createSchema(self::metadata($em));
    }

    public static function drop(EntityManagerInterface $em): void
    {
        new SchemaTool($em)->dropSchema(self::metadata($em));
    }

    public static function recreate(EntityManagerInterface $em): void
    {
        $schemaTool = new SchemaTool($em);
        $metadata = self::metadata($em);

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    /**
     * Deduplicated because a mapping driver chain can legitimately expose the same class
     * through two overlapping namespaces, and SchemaTool would then create its table twice.
     *
     * @return list<ClassMetadata<object>>
     */
    private static function metadata(EntityManagerInterface $em): array
    {
        $unique = [];

        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            $unique[$metadata->getName()] = $metadata;
        }

        return array_values($unique);
    }
}
