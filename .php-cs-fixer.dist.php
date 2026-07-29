<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->append([__FILE__, __DIR__.'/rector.php']);

return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    // Risky fixers are opted into one at a time. The only one enabled is
    // `declare_strict_types`, which is "risky" solely because adding the declaration to a
    // file that was never written for it can change behaviour — every file here already
    // has it, so the rule is a guard, not a migration.
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile('.php-cs-fixer.cache')
    ->setRules([
        '@PER-CS2.0' => true,
        '@Symfony' => true,

        'declare_strict_types' => true,
        'blank_line_after_opening_tag' => true,

        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'no_unused_imports' => true,

        'trailing_comma_in_multiline' => [
            'after_heredoc' => true,
            'elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters'],
        ],

        // Docblocks in this codebase exist to carry array shapes, generics, and public-API
        // prose. Nothing here may rewrite prose, and `@throws`/`@param` that repeat a native
        // signature are noise, so they go.
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'allow_unused_params' => false,
            'remove_inheritdoc' => true,
        ],
        'phpdoc_to_comment' => false,

        'single_line_throw' => false,
        'concat_space' => ['spacing' => 'none'],
    ]);
