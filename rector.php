<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use Rector\EarlyReturn\Rector\If_\ChangeOrIfContinueToMultiContinueRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;
use Rector\ValueObject\PhpVersion;

/*
 * Only the PHP level sets and the code-quality / dead-code / early-return presets are
 * enabled. rector/rector-symfony, rector/rector-doctrine and rector/rector-phpunit are not
 * dependencies of this bundle, so their sets are deliberately absent — asking for
 * `withComposerBased(symfony: true, doctrine: true, phpunit: true)` here would silently
 * contribute nothing. Add the packages first if you want those sets.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        earlyReturn: true,
    )
    ->withImportNames(importShortClasses: false)
    ->withCache(__DIR__.'/build/rector')
    ->withSkip([
        // AuditLog is a mapped Doctrine entity: the ORM hydrates properties that never pass
        // through a constructor. Promoting or freezing them breaks hydration.
        ReadOnlyPropertyRector::class => [
            __DIR__.'/src/Entity',
        ],
        ClassPropertyAssignToConstructorPromotionRector::class => [
            __DIR__.'/src/Entity',
        ],

        // Rewrites `null === $x` into `!$x instanceof Foo`. A null check reads as a null
        // check here; the type is already in the signature.
        FlipTypeControlToUseExclusiveTypeRector::class,

        // Turns one `||` guard into two `if`/`continue` blocks. More branches, same meaning.
        ChangeOrIfContinueToMultiContinueRector::class,

        // `new Foo()->bar()` is valid on the supported PHP range but reads worse than the
        // parenthesised form, and it is not something a contributor should be forced into.
        NewMethodCallWithoutParenthesesRector::class,

        // A private helper that touches no state is written `static` here on purpose: the
        // signature says "pure". Demoting it to an instance method hides that.
        LocallyCalledStaticMethodToNonStaticRector::class,
    ]);
