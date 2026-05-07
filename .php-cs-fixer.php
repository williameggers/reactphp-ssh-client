<?php declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
;

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PhpCsFixer' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'line_ending' => true,
        'ordered_imports' => true,
        'trim_array_spaces' => true,
        'linebreak_after_opening_tag' => false,
        'blank_line_after_opening_tag' => false,
    ])
    ->setLineEnding("\n")
    ->setFinder($finder)
;
