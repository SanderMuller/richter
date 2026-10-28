<?php declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Carbon\Rector\FuncCall\TimeFuncCallToCarbonRector;
use Rector\CodeQuality\Rector\ClassMethod\InlineArrayReturnAssignRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\CodingStyle\Rector\String_\UseClassKeywordForClassNameResolutionRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php80\Rector\Ternary\TernaryToNullsafeCoalesceRector;
use Rector\PHPUnit\PHPUnit120\Rector\Class_\AllowMockObjectsForDataProviderRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use RectorLaravel\Set\LaravelSetList;

// Rector rejects a containerCacheDirectory that does not exist yet.
is_dir(__DIR__ . '/.cache/phpstan') || mkdir(__DIR__ . '/.cache/phpstan', recursive: true);

return RectorConfig::configure()
    ->withCache(
        cacheDirectory: './.cache/rector',
        cacheClass: FileCacheStorage::class,
        // Rector boots a PHPStan container and writes the same reflection cache
        // entries PHPStan does, so it shares PHPStan's tmpDir: one copy, not two.
        containerCacheDirectory: './.cache/phpstan',
    )
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/workbench',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
    )
    ->withComposerBased(phpunit: true)
    ->withAttributesSets()
    ->withImportNames()
    ->withFluentCallNewLine()
    ->withParallel(300, 15, 15)
    ->withMemoryLimit('3G')
    ->withPhpSets(php84: true)
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
    ])
    ->withSkip([
        AddArrowFunctionReturnTypeRector::class,
        InlineArrayReturnAssignRector::class,
        PrivatizeFinalClassMethodRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,
        // Its `$x?->prop ?? $d` output fails PHPStan's nullsafe.neverNull.
        TernaryToNullsafeCoalesceRector::class,
        // On a nullable enum, its rewrite lets the deadCode set delete reachable code.
        ExplicitNullableObjectInstanceRector::class,
        // Class-name strings are analysis *data* here — tests build PHP source as strings, and src
        // matches namespace prefixes. Converting them to ::class constants drops the leading
        // backslash of rooted names and changes what the parser sees.
        StringClassNameToClassConstantRector::class,
        UseClassKeywordForClassNameResolutionRector::class,
        // The fixture project is verbatim input for Laravel Brain and the tracers — "improving" it
        // changes what the tests analyze (Rector removed the fixture Kernel's alias map as dead code).
        __DIR__ . '/tests/Fixtures',
        // GraphCache's racy-clean guard must compare file mtimes/ctimes to the real wall clock;
        // Date::now() is fakeable (Carbon::setTestNow), which a host app could freeze and silently
        // disable the guard. Keep the raw time() call there.
        TimeFuncCallToCarbonRector::class => [__DIR__ . '/src/Graph/GraphCache.php'],
        // False positive: it reads the test's own `method()` helper as a mock call.
        AllowMockObjectsForDataProviderRector::class => [__DIR__ . '/tests/Unit/ImpactFormatterTest.php'],
    ]);
