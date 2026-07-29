<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\ORM\Events;
use Th3Mouk\AuditTrail\Actor\ActorResolverInterface;
use Th3Mouk\AuditTrail\Actor\ChainActorResolver;
use Th3Mouk\AuditTrail\AuditLogger;
use Th3Mouk\AuditTrail\AuditLoggerInterface;
use Th3Mouk\AuditTrail\Capture\ActionResolverInterface;
use Th3Mouk\AuditTrail\Capture\CaptureGateInterface;
use Th3Mouk\AuditTrail\Capture\ChainActionResolver;
use Th3Mouk\AuditTrail\Capture\ChainFieldExclusion;
use Th3Mouk\AuditTrail\Capture\ChangeSetSerializer;
use Th3Mouk\AuditTrail\Capture\DefaultLabelResolver;
use Th3Mouk\AuditTrail\Capture\DefaultScopeResolver;
use Th3Mouk\AuditTrail\Capture\EntityIdResolver;
use Th3Mouk\AuditTrail\Capture\FieldExclusionInterface;
use Th3Mouk\AuditTrail\Capture\Gate\CascadeSuppressionGate;
use Th3Mouk\AuditTrail\Capture\Gate\ChainCaptureGate;
use Th3Mouk\AuditTrail\Capture\Gate\EnabledGate;
use Th3Mouk\AuditTrail\Capture\LabelResolverInterface;
use Th3Mouk\AuditTrail\Capture\ScopeResolverInterface;
use Th3Mouk\AuditTrail\Capture\Value\ChainValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\DateTimeValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\EntityReferenceValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\EnumValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\ScalarValueSerializer;
use Th3Mouk\AuditTrail\Capture\Value\StringableValueSerializer;
use Th3Mouk\AuditTrail\Capture\ValueSerializerInterface;
use Th3Mouk\AuditTrail\Context\RequestContext;
use Th3Mouk\AuditTrail\Context\RequestContextListener;
use Th3Mouk\AuditTrail\EventListener\AuditLogListener;
use Th3Mouk\AuditTrail\EventListener\TableNameListener;
use Th3Mouk\AuditTrail\Metadata\AuditableResolver;
use Th3Mouk\AuditTrail\Metadata\AuditTypeResolver;
use Th3Mouk\AuditTrail\Metadata\AuditTypeWarmer;
use Th3Mouk\AuditTrail\Metadata\FieldPolicyResolver;
use Th3Mouk\AuditTrail\Repository\AuditLogRepository;
use Th3Mouk\AuditTrail\Storage\AuditStorageInterface;
use Th3Mouk\AuditTrail\Storage\DoctrineAuditStorage;
use Th3Mouk\AuditTrail\Storage\FlushState;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->private();

    $services->set(AuditableResolver::class);
    $services->set(FieldPolicyResolver::class)
        ->arg('$defaultMask', param('audit_trail.mask'));
    $services->set(AuditTypeResolver::class);
    $services->set(AuditTypeWarmer::class)
        ->tag('kernel.cache_warmer');
    $services->set(EntityIdResolver::class);

    $services->set(DefaultLabelResolver::class);
    $services->set(DefaultScopeResolver::class);

    $services->set(ScalarValueSerializer::class)
        ->tag('audit_trail.value_serializer', ['priority' => 200]);

    $services->set(DateTimeValueSerializer::class)
        ->tag('audit_trail.value_serializer', ['priority' => 150]);

    $services->set(EnumValueSerializer::class)
        ->tag('audit_trail.value_serializer', ['priority' => 100]);

    $services->set(EntityReferenceValueSerializer::class)
        ->tag('audit_trail.value_serializer', ['priority' => 50]);

    $services->set(StringableValueSerializer::class)
        ->tag('audit_trail.value_serializer', ['priority' => -100]);

    $services->set(ChainValueSerializer::class)
        ->args([tagged_iterator('audit_trail.value_serializer')]);

    $services->set(ChangeSetSerializer::class);

    $services->set(RequestContext::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(RequestContextListener::class)
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'priority' => 1024]);

    $services->set(ChainActorResolver::class)
        ->args([tagged_iterator('audit_trail.actor_resolver')]);

    $services->set(EnabledGate::class)
        ->arg('$enabled', param('audit_trail.enabled'))
        ->tag('audit_trail.capture_gate', ['priority' => 200])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(CascadeSuppressionGate::class)
        ->arg('$suppressCascadeChildren', param('audit_trail.capture.suppress_cascade_children'))
        ->tag('audit_trail.capture_gate', ['priority' => 100]);

    $services->set(ChainCaptureGate::class)
        ->args([tagged_iterator('audit_trail.capture_gate')]);

    $services->set(ChainActionResolver::class)
        ->args([tagged_iterator('audit_trail.action_resolver')]);

    $services->set(ChainFieldExclusion::class)
        ->args([tagged_iterator('audit_trail.field_exclusion')]);

    $services->set(FlushState::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(DoctrineAuditStorage::class);

    $services->set(AuditLogger::class)
        ->arg('$enabled', param('audit_trail.enabled'));

    $services->set(AuditLogRepository::class)
        ->tag('doctrine.repository_service');

    $services->set(AuditLogListener::class)
        ->arg('$stateOnCreate', param('audit_trail.capture.state_on_create'))
        ->arg('$stateOnDelete', param('audit_trail.capture.state_on_delete'))
        ->tag('doctrine.event_listener', ['event' => Events::onFlush]);

    $services->set(TableNameListener::class)
        ->arg('$tableName', param('audit_trail.table_name'))
        ->tag('doctrine.event_listener', ['event' => Events::loadClassMetadata]);

    $services->alias(AuditLoggerInterface::class, AuditLogger::class)->public();
    $services->alias(AuditStorageInterface::class, DoctrineAuditStorage::class)->public();
    $services->alias(LabelResolverInterface::class, DefaultLabelResolver::class)->public();
    $services->alias(ScopeResolverInterface::class, DefaultScopeResolver::class)->public();
    $services->alias(ActorResolverInterface::class, ChainActorResolver::class)->public();
    $services->alias(ValueSerializerInterface::class, ChainValueSerializer::class)->public();
    $services->alias(CaptureGateInterface::class, ChainCaptureGate::class)->public();
    $services->alias(ActionResolverInterface::class, ChainActionResolver::class)->public();
    $services->alias(FieldExclusionInterface::class, ChainFieldExclusion::class)->public();
};
