<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Case;

use PHPUnit\Framework\TestCase;
use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\RecordingAuditStorage;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\RecordingLogger;

/**
 * The base of the pyramid: capture, assessed without a database.
 *
 * A collaborator that only needs somewhere to put entries gets `$this->storage`; a test then
 * describes the outcome with the same assertions the integration suite uses. Most of the
 * suite should live here, because nothing in the capture contract — policies, masking, the
 * row-trigger rule, value mapping, scope resolution — needs a schema to be true.
 */
abstract class AuditTrailTestCase extends TestCase
{
    use AuditEntryAssertions;
    use AuditEntryBuilders;

    protected RecordingAuditStorage $storage;

    protected RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = new RecordingAuditStorage();
        $this->logger = new RecordingLogger();
    }

    /**
     * @return list<AuditEntry>
     */
    protected function recordedEntries(): array
    {
        return $this->storage->entries();
    }
}
