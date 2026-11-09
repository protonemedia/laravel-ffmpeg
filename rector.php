<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
    ])
    ->withSkip([
        // Laravel groups trait imports on one line, as in `use Conditionable, Macroable;`.
        SeparateMultiUseImportsRector::class,
        // Closures fill $entries by reference, which Rector cannot see.
        RemoveAlwaysTrueIfConditionRector::class => [
            __DIR__.'/src/PendingLiveHls.php',
        ],
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withPhpSets();
