<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\EventListener;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Th3Mouk\AuditTrail\Entity\AuditLog;

/**
 * Applies the `audit_trail.table_name` option to the one table the bundle owns.
 *
 * The mapping is renamed rather than duplicated, so an application already carrying an
 * `audit_logs` table of its own can host the trail elsewhere without forking the entity.
 */
final readonly class TableNameListener
{
    public function __construct(private string $tableName)
    {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if (AuditLog::class !== $metadata->getName()) {
            return;
        }

        if ($this->tableName === $metadata->getTableName()) {
            return;
        }

        $metadata->setPrimaryTable(['name' => $this->tableName]);
    }
}
