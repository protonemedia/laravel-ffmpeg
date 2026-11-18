<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use ProtoneMedia\LaravelFFMpeg\Tests\CodeStyle\BlankLineBetweenCallAndAssignmentFixer;

// Pint cannot load custom fixers, so this configuration runs only the
// rules that Pint lacks. Pint formats everything else in pint.json.
return (new Config)
    ->setFinder(Finder::create()->in(__DIR__.'/src'))
    ->registerCustomFixers([new BlankLineBetweenCallAndAssignmentFixer])
    ->setRules([BlankLineBetweenCallAndAssignmentFixer::NAME => true])
    ->setUsingCache(false);
