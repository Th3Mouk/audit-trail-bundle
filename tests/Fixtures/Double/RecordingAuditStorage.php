<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Double;

use Th3Mouk\AuditTrail\Model\AuditEntry;
use Th3Mouk\AuditTrail\Storage\AuditStorageInterface;

/**
 * Keeps every entry in memory instead of a database.
 *
 * This is the workhorse of the unit suite: capture is exercised end to end, assertions run
 * against real AuditEntry objects, and no schema exists to slow anything down.
 *
 * Given an inner storage it becomes a transparent spy instead of a replacement, which is
 * how the kernel test cases watch what the Doctrine storage receives.
 */
final class RecordingAuditStorage implements AuditStorageInterface
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    public function __construct(
        private readonly ?AuditStorageInterface $next = null,
    ) {
    }

    public function store(AuditEntry $entry): void
    {
        $this->entries[] = $entry;

        $this->next?->store($entry);
    }

    /**
     * @return list<AuditEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    public function first(): ?AuditEntry
    {
        return $this->entries[0] ?? null;
    }

    public function latest(): ?AuditEntry
    {
        return [] === $this->entries ? null : $this->entries[\count($this->entries) - 1];
    }

    public function forget(): void
    {
        $this->entries = [];
    }
}
