<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Storage;

use Th3Mouk\AuditTrail\Model\AuditEntry;

/**
 * Where recorded facts go.
 *
 * The shipped Doctrine implementation writes each entry inside the very transaction that
 * produced it, so a rolled-back change takes its audit entry with it. Replacing this
 * service is supported, but any implementation that leaves the transaction gives that
 * atomicity up — an explicit trade, not an accident.
 */
interface AuditStorageInterface
{
    public function store(AuditEntry $entry): void;
}
