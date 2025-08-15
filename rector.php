<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Ensure Rector knows we are targeting PHP 8.1+ syntax and features
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        typeDeclarations: true,       // Add param/return/var types where possible
        earlyReturn: true,            // Replace nested ifs with early returns
        strictBooleans: true,         // Use strict bool checks
        phpunitCodeQuality: true,     // Modernise PHPUnit usage
        deadCode: true                // Remove unused code, vars, imports, etc.
    )
    ->withSets([
        LevelSetList::UP_TO_PHP_84,   // Enforces all rules up to PHP 8.4
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,    // Add missing type declarations aggressively
        SetList::PRIVATIZATION,       // Make props/methods private if possible
        SetList::STRICT_BOOLEANS,     // Strict boolean expressions
    ])->withPHPStanConfigs([
        __DIR__ . '/phpstan.neon.dist',
    ]);