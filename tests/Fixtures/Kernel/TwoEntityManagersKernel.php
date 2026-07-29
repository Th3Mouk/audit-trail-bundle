<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Kernel;

/**
 * Two entity managers over one connection, with the same auditable entities mapped in both.
 *
 * Doctrine registers an event listener on every connection unless the tag names one, so a flush of
 * the second manager reaches the capture listener as well. Recording it would enlist the entry in a
 * different UnitOfWork from the change it describes — written outside its transaction, which is the
 * single guarantee this bundle makes. One manager owns the trail, and this kernel is what proves the
 * other one is left alone.
 */
final class TwoEntityManagersKernel extends TestKernel
{
    public const string AUDITED_MANAGER = 'default';
    public const string UNAUDITED_MANAGER = 'secondary';

    #[\Override]
    protected function doctrineConfig(): array
    {
        $inherited = parent::doctrineConfig();
        $perManager = $this->entityManagerConfig();

        $orm = [
            'default_entity_manager' => self::AUDITED_MANAGER,
            'entity_managers' => [
                self::AUDITED_MANAGER => $perManager,
                // The same fixtures, mapped again: the entity class cannot be what tells the two
                // managers apart, so only the manager the change was flushed through can.
                self::UNAUDITED_MANAGER => $perManager,
            ],
        ];

        if (self::declaresNativeLazyObjectsOption()) {
            $orm['enable_native_lazy_objects'] = true;
        }

        return ['dbal' => $inherited['dbal'], 'orm' => $orm];
    }
}
