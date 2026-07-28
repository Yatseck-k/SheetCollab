<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/app/Orchid/Screens/Examples',
        __DIR__.'/app/Orchid/Layouts/Examples',
    ])
    ->withSets([
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
        SetList::TYPE_DECLARATION,
        LevelSetList::UP_TO_PHP_84,
    ])
    ->withImportNames(importShortClasses: false)
    ->withPhpVersion(PhpVersion::PHP_84);
