<?php declare(strict_types=1);

use Rector\Carbon\Rector\FuncCall\TimeFuncCallToCarbonRector;
use Rector\CodingStyle\Rector\String_\UseClassKeywordForClassNameResolutionRector;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\PHPUnit\PHPUnit120\Rector\Class_\AllowMockObjectsForDataProviderRector;
use SanderMuller\RectorPreset\Preset;

return Preset::package()
    ->withPhpSets(php84: true)
    ->withSkip([
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
