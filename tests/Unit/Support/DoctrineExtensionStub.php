<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Unit\Support;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Just enough of DoctrineBundle for `prepend()` to believe Doctrine is installed.
 */
final class DoctrineExtensionStub extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
    }

    #[\Override]
    public function getAlias(): string
    {
        return 'doctrine';
    }
}
