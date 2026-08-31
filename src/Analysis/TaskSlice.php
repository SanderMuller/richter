<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Support\Fqcn;
use SanderMuller\Richter\Support\HubFold;

/**
 * The one document an agent working mid-feature can act on: the surfaces this task owns, which of them
 * no test proves, the hazards, the findings, and the tests to run.
 *
 * Composition, not new analysis. Every value here already exists in `detect-changes` or
 * `affected-tests`; what did not exist was a single call that answers "what does this task own, and
 * what should I run" without a consumer joining two documents and filtering one of them by hand.
 *
 * Two rules make this safe to hand an agent:
 *
 * - The SELECTION is never narrowed. `affected-tests` selects exactly what it selects, hub list or
 *   not. What this document does is DEGRADE `affectedTestsDeterminable` to false when the keep set
 *   folded rows away, because the test list was computed for the whole diff and is therefore not
 *   complete for the keep set. Strictly more conservative, never less.
 * - Nothing here reaches `risk`, the gate or the selection. A hub list is a project's policy, not
 *   evidence about its code.
 *
 * @internal
 */
final readonly class TaskSlice
{
    /**
     * @param  array<string, mixed>  $document  a {@see JsonPresenter::detectChanges()} document
     * @param  array{base: string, determinable: bool, reasons: list<string>, tests: list<string>, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>}  $selection  the caller strips `untrackedFiles` first: it feeds a stderr note, never a document
     * @return array<string, mixed>
     */
    public static function compose(array $document, array $selection): array
    {
        /** @var list<string> $entryPoints */
        $entryPoints = $document['entryPoints'] ?? [];
        /** @var array{kept: list<string>, droppedHub: int} $keepSet */
        $keepSet = $document['entryPointKeepSet'] ?? ['kept' => $entryPoints, 'droppedHub' => 0];
        /** @var array<string, string> $references */
        $references = $document['entryPointTestReferences'] ?? [];
        /** @var array<string, bool|null> $verification */
        $verification = $document['verification'] ?? [];
        /** @var array<string, int> $changed */
        $changed = $document['changed'] ?? [];
        /** @var array<string, array{via: string, ownReach: int}> $attribution */
        $attribution = $document['entryPointAttribution'] ?? [];

        $kept = $keepSet['kept'];
        $droppedHub = $keepSet['droppedHub'];

        $determinable = $selection['determinable'];
        $reasons = $selection['reasons'];

        if ($determinable && $droppedHub > 0) {
            $determinable = false;
            $reasons[] = 'the keep set folded hub-reached surfaces away, so this selection — computed for the whole diff — is not complete for it';
        }

        $verificationFalse = self::notVerified($verification);
        $hubs = array_keys(HubFold::counts($entryPoints, $kept, $attribution));

        return [
            'base' => $document['base'] ?? '',
            'kept' => $kept,
            'unreferencedKept' => self::withoutProvenTest($kept, $references),
            'hazards' => $document['hazards'] ?? [],
            'findings' => $document['findings'] ?? [],
            'verificationFalse' => $verificationFalse,
            'runImpact' => $kept === [] && $changed !== [],
            'runImpactOn' => self::runImpactOn($verificationFalse, $kept, $changed, $hubs),
            'affectedTestsDeterminable' => $determinable,
            'affectedTests' => $selection['tests'],
            'affectedFrontendTests' => $selection['frontendTests'],
            'affectedTestsReasons' => array_values($reasons),
            'droppedHubCount' => $droppedHub,
            'entryPointCount' => count($entryPoints),
            'changedFiles' => array_keys($changed),
            'risk' => $document['risk'] ?? '',
            'riskCause' => $document['riskCause'] ?? '',
            'unresolved' => ($document['unresolved'] ?? false) === true,
            'lowConfidence' => ($document['lowConfidence'] ?? false) === true,
        ];
    }

    /**
     * The kept surfaces no test is known to PROVE.
     *
     * Both weak states count, not only `unreferenced`. A surface whose only test contains no
     * assertion the scan recognises is referenced and unproven at the same time, and this package
     * added that third state precisely because a reference is not coverage — reading it as covered
     * here would throw the distinction away at the one place an agent acts on it.
     *
     * @param  list<string>  $kept
     * @param  array<string, string>  $references
     * @return list<string>
     */
    private static function withoutProvenTest(array $kept, array $references): array
    {
        return array_values(array_filter(
            $kept,
            static fn (string $node): bool => ($references[$node] ?? 'unreferenced') !== 'referenced',
        ));
    }

    /**
     * What the risk ladder graded and did not find verified.
     *
     * `null` is in, and that is the whole point of the name: it means the reference state could not be
     * checked at all, which the ladder already reads as unverified. Keeping only `false` would report
     * an unknown as a pass.
     *
     * @param  array<string, bool|null>  $verification
     * @return list<string>
     */
    private static function notVerified(array $verification): array
    {
        $unverified = [];

        foreach ($verification as $node => $verified) {
            if ($verified !== true) {
                $unverified[] = $node;
            }
        }

        return $unverified;
    }

    /**
     * The classes worth an `impact` call when the keep set is empty.
     *
     * A loader, a data object or a builder is not an entry surface, so a real change to one produces
     * no kept row at all. Answering "nothing" there leaves the reader with no next step, so the
     * document names the classes to analyse directly instead.
     *
     * Hub-attributed files are dropped from that list first, and every FQCN comes from
     * {@see Fqcn::fromPath()} rather than a literal `App\` prefix — this package resolves the root
     * namespace from composer's autoload map, and an application that maps another root to `app/`
     * would otherwise be handed class names that do not exist.
     *
     * @param  list<string>  $verificationFalse
     * @param  list<string>  $kept
     * @param  array<string, int>  $changed
     * @param  list<string>  $hubs
     * @return list<string>
     */
    private static function runImpactOn(array $verificationFalse, array $kept, array $changed, array $hubs): array
    {
        $classes = $verificationFalse;

        if ($kept === []) {
            $files = array_values(array_filter(array_keys($changed), static fn (string $file): bool => ! in_array($file, $hubs, strict: true)));

            foreach (($files === [] ? array_keys($changed) : $files) as $file) {
                if (str_starts_with($file, 'app/') && str_ends_with($file, '.php')) {
                    $classes[] = Fqcn::fromPath($file);
                }
            }
        }

        return array_values(array_unique($classes));
    }
}
