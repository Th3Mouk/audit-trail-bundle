<?php

declare(strict_types=1);

namespace Th3Mouk\AuditTrail\Tests\Fixtures\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Th3Mouk\AuditTrail\Storage\AuditStorageInterface;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\RecordingAuditStorage;
use Th3Mouk\AuditTrail\Tests\Fixtures\Double\RecordingLogger;

/**
 * Wraps the storage in a spy, and makes the application logger readable instead of noisy.
 *
 * Integration tests keep the shipped Doctrine storage — and therefore the transactional
 * guarantee that is the point of it — while still being able to look at the exact
 * AuditEntry objects capture produced, before any column truncation or JSON round trip. The
 * decoration is conditional so the pass stays harmless when the storage is absent; tests
 * then fall back to the persisted rows.
 *
 * `logger` is claimed rather than decorated. FrameworkBundle only supplies its fallback
 * logger from `LoggerPass`, which runs after this pass, so there is nothing here to
 * decorate yet — and that fallback writes every record it receives to the process output,
 * which PHPUnit reports as a test printing unexpected output the moment a scenario exercises
 * an error path. Registering the recording logger under that id gets in first: `LoggerPass`
 * finds an application logger already in place and leaves it alone, records become
 * assertable, and nothing reaches the output stream.
 */
final class SpyOnAuditStoragePass implements CompilerPassInterface
{
    private const string LOGGER_ID = 'logger';

    public function process(ContainerBuilder $container): void
    {
        if ($container->has(AuditStorageInterface::class)) {
            $container
                ->register(RecordingAuditStorage::class, RecordingAuditStorage::class)
                ->setDecoratedService(AuditStorageInterface::class)
                ->setArguments([new Reference(RecordingAuditStorage::class.'.inner')])
                ->setPublic(true);
        }

        if ($container->has(self::LOGGER_ID)) {
            $container
                ->register(RecordingLogger::class, RecordingLogger::class)
                ->setDecoratedService(self::LOGGER_ID)
                ->setArguments([new Reference(RecordingLogger::class.'.inner')])
                ->setPublic(true);

            return;
        }

        $container->register(self::LOGGER_ID, RecordingLogger::class);
        $container->setAlias(RecordingLogger::class, self::LOGGER_ID)->setPublic(true);
    }
}
