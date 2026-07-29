<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Bridge\Gedmo\Case;

use Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter;

/**
 * The Gedmo arrangement, with a second entity manager the trail does not belong to.
 *
 * `MultipleEntityManagersTest` proves the capture listener ignores a foreign flush, but it runs on
 * a kernel with no Gedmo at all — so the translation listener, which is a second `onFlush`
 * subscriber registered on every connection just the same, was never covered by it.
 */
final class TwoEntityManagersGedmoKernel extends GedmoBridgeKernel
{
    public const string AUDITED_MANAGER = 'default';
    public const string UNAUDITED_MANAGER = 'secondary';

    #[\Override]
    protected function doctrineConfig(): array
    {
        $inherited = parent::doctrineConfig();

        // Filters are declared per manager once `entity_managers` is explicit; the same fixtures are
        // mapped in both, so only the manager a change was flushed through can tell them apart.
        $perManager = [
            ...$this->entityManagerConfig(),
            'filters' => [
                'soft_deleteable' => ['class' => SoftDeleteableFilter::class, 'enabled' => true],
            ],
        ];

        $orm = [
            'default_entity_manager' => self::AUDITED_MANAGER,
            'entity_managers' => [
                self::AUDITED_MANAGER => $perManager,
                self::UNAUDITED_MANAGER => $perManager,
            ],
        ];

        if (self::declaresNativeLazyObjectsOption()) {
            $orm['enable_native_lazy_objects'] = true;
        }

        return ['dbal' => $inherited['dbal'], 'orm' => $orm];
    }
}
