<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\ORM\Events;
use Gedmo\Translatable\TranslatableListener;
use Th3Mouk\AuditTrail\Bridge\Gedmo\SoftDeleteableActionResolver;
use Th3Mouk\AuditTrail\Bridge\Gedmo\TranslationAuditListener;
use Th3Mouk\AuditTrail\Bridge\Gedmo\TranslationFieldExclusion;

/*
 * The two pieces are registered together and taken apart again by AuditTrailExtension:
 * `bridges.gedmo.soft_deleteable` false removes the action resolver, `translatable` false
 * removes the listener and its field exclusion, and `bridges.gedmo.listener_priority`
 * rewrites the onFlush tag below. A service file cannot branch on a parameter, and the
 * priority has to be a real integer by the time Doctrine's listener pass reads the tag.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->private();

    $services->set(SoftDeleteableActionResolver::class)
        ->tag('audit_trail.action_resolver');

    $services->set(TranslationAuditListener::class)
        ->arg('$translatableListener', service(TranslatableListener::class)->nullOnInvalid())
        ->arg('$logger', service('logger')->nullOnInvalid())
        ->tag('doctrine.event_listener', ['event' => Events::onFlush]);

    $services->set(TranslationFieldExclusion::class)
        ->tag('audit_trail.field_exclusion');
};
