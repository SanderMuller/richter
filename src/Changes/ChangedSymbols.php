<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Support\Fqcn;
use SanderMuller\Richter\Support\GitProjectPaths;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tracers\EagerLoadStringChecker;
use SanderMuller\Richter\Tracers\FeatureGateChecker;
use SanderMuller\Richter\Tracers\InertiaPageChecker;

/** Resolves which class members a branch changed (and how) vs a base ref, so impact seeds on the member not the file. {@see resolve()} holds git plumbing; {@see classifyFile()} is pure over source + hunk. */
final class ChangedSymbols
{
    /**
     * @param  string  $head  `HEAD` diffs the working tree against the merge-base with `$base`, so
     *   uncommitted and staged edits are included and line up with {@see headSource()}'s working-tree
     *   read (no hunk/source desync); any other ref replays its committed tree via `$base...$head`,
     *   reading both sides from git so a historical diff (e.g. a benchmark fix commit) is unaffected.
     * @return list<ChangedFileSymbols>
     *
     * @throws RuntimeException when the git diff fails (missing base ref / not a checkout).
     */
    public static function resolve(string $base, string $head = 'HEAD'): array
    {
        // Resolved first: HEAD mode needs it to build the diff range below, and a broken base ref
        // fails here before a second, redundant git invocation would fail again in the diff itself.
        $mergeBaseResult = Process::path(base_path())->run(['git', 'merge-base', '--end-of-options', $base, $head]);

        if (! $mergeBaseResult->successful()) {
            throw new RuntimeException("git diff against '{$base}' failed: " . trim($mergeBaseResult->errorOutput()));
        }

        $mergeBase = trim($mergeBaseResult->output());

        // HEAD mode compares the working tree against the merge-base (a single ref diffs the working
        // tree against it); any other ref keeps the three-dot committed-tree form untouched.
        $diffRange = $head === 'HEAD' ? [$mergeBase] : ["{$base}...{$head}"];

        // `--relative` scopes the diff to the process cwd (base_path()) and emits paths relative to
        // it, so the `app/`/`resources/` gates below match whether base_path() is the repo root or a
        // subdirectory of a larger repo. At the repo root it is a no-op (paths are already
        // root-relative and nothing is out of scope), so the common case is byte-for-byte unchanged.
        $diff = Process::path(base_path())->run(['git', '-c', 'core.quotepath=off', 'diff', '-U0', '--relative', '--end-of-options', ...$diffRange]);

        if (! $diff->successful()) {
            throw new RuntimeException("git diff against '{$base}' failed: " . trim($diff->errorOutput()));
        }

        // Resolved once here (not per file): the diff paths are base_path()-relative via `--relative`,
        // but `git show {ref}:PATH` resolves PATH against the repo root, so baseSource()/headSource()
        // re-root through this prefix. Empty at the repo root, so the common case is unchanged. Null
        // (rev-parse failed in a checkout the diff already proved valid — near-unreachable) coalesces
        // to '': a wrong `git show` path then returns null, which the unreadable-base guard below and
        // the existing unreadable-head guard both turn into a coarse seed — fail-closed, never
        // falsely additive.
        $prefix = GitProjectPaths::prefix() ?? '';

        $changed = [];

        // One checker shared across every classified file: its instance cache bounds the model
        // scan to once per invocation, while a fresh run always rebuilds the set — a relation
        // added since the previous run (same long-lived process) is seen, never a false alarm.
        $eagerLoadChecker = new EagerLoadStringChecker();
        $featureGateChecker = new FeatureGateChecker(RichterConfig::featureGateMethods());
        $inertiaPageChecker = new InertiaPageChecker();
        $frontendChanges = new FrontendChanges();

        foreach (UnifiedDiffParser::parse($diff->output()) as $file => $hunk) {
            if (str_starts_with($file, 'app/') && str_ends_with($file, '.php')) {
                // A 100%-similarity rename emits no hunks, but the old FQCN disappears — every caller of it
                // breaks. Never cosmetic: seed the vanished old FQCN directly (head-tree callers still
                // reference it) and the new FQCN coarsely (a class-level change with no member to pin).
                if ($hunk['added'] === [] && $hunk['removed'] === [] && $hunk['oldPath'] !== $file) {
                    $changed[] = new ChangedFileSymbols($file, Fqcn::fromPath($file), [
                        new MemberChange('', MemberChange::KIND_CLASS, MemberChange::CHANGE_MODIFIED, resolvable: false),
                    ], cosmeticOnly: false, directSeeds: [Fqcn::fromPath($hunk['oldPath'])]);

                    continue;
                }

                $headSrc = self::headSource($head, $file, $prefix);

                // An unreadable head source (failed `git show` on a diff that *adds* lines, so the file
                // must exist at head) cannot classify — an empty string would read as cosmetic/additive,
                // the forbidden falsely-empty "no impact". Seed coarsely instead. A pure deletion
                // legitimately has no head source and classifies from the base side below.
                if ($headSrc === null && $hunk['added'] !== []) {
                    $changed[] = new ChangedFileSymbols($file, Fqcn::fromPath($file), [
                        new MemberChange('', MemberChange::KIND_CLASS, MemberChange::CHANGE_MODIFIED, resolvable: false),
                    ], cosmeticOnly: false);

                    continue;
                }

                $headSrc ??= '';
                // Use the pre-change path so a rename still resolves the base-side members.
                $baseSrc = self::baseSource($mergeBase, $hunk['oldPath'], $prefix);

                $changed[] = self::classifyFile($file, $headSrc, $baseSrc, ['added' => $hunk['added'], 'removed' => $hunk['removed']], $eagerLoadChecker, $featureGateChecker, $inertiaPageChecker, isNew: $hunk['isNew']);

                continue;
            }

            // A changed frontend file (opt-in via richter.frontend.roots) seeds the route nodes of
            // the backend endpoints it references — Wayfinder imports and Ziggy route() calls.
            if ($frontendChanges->handles($file)) {
                $headSrc = self::headSource($head, $file, $prefix);

                // Same honesty rule as the PHP branch above: a diff that adds lines proves the file
                // exists at head, so an unreadable head is an I/O failure, not a deletion — scanning
                // '' would read as a determined "no references", the forbidden falsely-empty result.
                if ($headSrc === null && $hunk['added'] !== []) {
                    $changed[] = new ChangedFileSymbols($file, '', [], cosmeticOnly: false,
                        findings: ['frontend source could not be read at head — references could not be checked'],
                        unresolvedFrontendReferences: true);

                    continue;
                }

                $changed[] = $frontendChanges->resolve($file, $headSrc, self::baseSource($mergeBase, $hunk['oldPath'], $prefix));

                continue;
            }

            // A changed Blade view carries no PHP member to pin, so it seeds its own view node — this
            // is where the authorization-flag and component-render surface lives, invisible otherwise.
            $viewSeed = BladeViews::seedForChangedFile($file);

            if ($viewSeed !== null) {
                // An unreadable view source just skips the flag note — the seed itself is unaffected.
                // Inline-script endpoint literals (`fetch('/api/…')` in Alpine/vanilla JS) ride along
                // as touched-surface seeds next to the view node.
                $headSrc = self::headSource($head, $file, $prefix);
                $changed[] = new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: [
                    $viewSeed,
                    ...$frontendChanges->inlineUriSeeds($headSrc, self::baseSource($mergeBase, $hunk['oldPath'], $prefix)),
                ], findings: $featureGateChecker->bladeFindingsFor($headSrc ?? ''));
            }
        }

        return $changed;
    }

    /**
     * Untracked (never `git add`-ed) files under `app/`, the Blade views root, or a configured
     * frontend root — the one gap `git diff` can never close: a brand-new uncommitted file shows in
     * no diff form, HEAD-mode or otherwise, so it stays invisible however faithfully `resolve()`
     * reads the rest of the working tree. Callers surface this as an honest stderr-only warning
     * (never stdout, so `--json`/`--plain` output contracts stay intact) rather than pretend the
     * analysis saw everything.
     *
     * @return list<string> project-relative paths; empty when `git status` fails or nothing matches
     */
    public static function untrackedRelevantFiles(): array
    {
        $status = Process::path(base_path())->run(['git', '-c', 'core.quotepath=off', 'status', '--porcelain', '--end-of-options']);

        if (! $status->successful()) {
            return [];
        }

        $roots = ['app/', 'resources/views/', ...array_map(
            static fn (string $root): string => rtrim($root, '/') . '/',
            RichterConfig::frontendRoots(),
        )];

        // `status --porcelain` prints repo-root-relative paths even from a subdirectory, so
        // GitProjectPaths re-roots each to base_path() before the roots (base_path()-relative) match,
        // dropping anything outside base_path()'s subtree and failing closed if the layout is unknown.
        $untrackedPaths = array_map(
            static fn (string $line): string => substr($line, 3),
            array_values(array_filter(
                explode("\n", $status->output()),
                static fn (string $line): bool => str_starts_with($line, '?? '),
            )),
        );

        return GitProjectPaths::relevantUntracked($untrackedPaths, $roots);
    }

    /** @param  array{added: list<array{line: int, text: string}>, removed: list<array{line: int, text: string}>}  $hunk */
    public static function classifyFile(string $file, string $headSrc, ?string $baseSrc, array $hunk, ?EagerLoadStringChecker $eagerLoadChecker = null, ?FeatureGateChecker $featureGateChecker = null, ?InertiaPageChecker $inertiaPageChecker = null, bool $isNew = false): ChangedFileSymbols
    {
        // A null base on a file that is NOT genuinely new is an unreadable base — an I/O failure (a
        // transient `git show` error, or a mis-rooted path in a nested-app repo). Classifying its head
        // members against the empty base would mark a real change's members CHANGE_ADDED (additive /
        // no-impact) and silently under-select — the cardinal rule's forbidden failure. Seed a coarse
        // class change. `$isNew` (from the diff's `--- /dev/null`, {@see UnifiedDiffParser}) is the only
        // signal that distinguishes this from a real new file, which is legitimately additive.
        if ($baseSrc === null && ! $isNew) {
            return new ChangedFileSymbols($file, Fqcn::fromPath($file), [self::coarseClassChange()], cosmeticOnly: false);
        }

        $head = MemberResolver::resolve($headSrc);
        $base = $baseSrc !== null ? MemberResolver::resolve($baseSrc) : ['parsed' => true, 'members' => [], 'classRanges' => []];

        $baseByKey = self::byKey($base['members']);
        $headByKey = self::byKey($head['members']);

        $members = [];

        // Modified/added: a HEAD member whose span a `+` line falls within.
        foreach ($head['members'] as $member) {
            if (self::textsIn($hunk['added'], $member['start'], $member['end']) === []) {
                continue;
            }

            $key = self::memberKey($member);
            $existedBefore = isset($baseByKey[$key]);

            if ($existedBefore && self::memberChangeIsCosmetic($hunk, $member, $baseByKey[$key])) {
                continue;
            }

            $members[] = new MemberChange(
                $member['name'],
                $member['kind'],
                self::changeTypeFor($existedBefore, $headSrc, $baseSrc, $member),
                $member['resolvable'],
            );
        }

        // Removed: a BASE member whose span a `-` line falls within and that is gone from HEAD.
        foreach ($base['members'] as $member) {
            $key = self::memberKey($member);
            if (isset($headByKey[$key])) {
                continue;
            }

            if (self::textsIn($hunk['removed'], $member['start'], $member['end']) === []) {
                continue;
            }

            $members[] = new MemberChange(
                $member['name'],
                $member['kind'],
                MemberChange::CHANGE_REMOVED,
                $member['resolvable'],
            );
        }

        // A new file ($baseSrc === null) is additive as a whole — its class header/braces must not read as a class-level modification.
        if ($baseSrc !== null && self::hasClassLevelChange($hunk, $head, $base)) {
            $members[] = self::coarseClassChange();
        }

        // An unparseable changed side must not read as cosmetic — a real change we can't map to a member falls back to a coarse class seed rather than seeding nothing.
        $parseFailed = (! $head['parsed'] && $hunk['added'] !== []) || ($baseSrc !== null && ! $base['parsed'] && $hunk['removed'] !== []);

        if ($members === [] && $parseFailed) {
            $members[] = self::coarseClassChange();
        }

        return new ChangedFileSymbols($file, Fqcn::fromPath($file), $members, $members === [], findings: self::sourceFindings($members, $head['members'], $headSrc, $eagerLoadChecker, $featureGateChecker, $inertiaPageChecker));
    }

    /**
     * Advisory notes about the changed source itself, from every source checker — nothing to note
     * on a file without member-level changes. Feature-gate and Inertia-page notes are scoped to
     * the CHANGED members' line spans (a class-level coarse change scans the whole file): an
     * untouched sibling method's flag check or render must never read as part of the change.
     *
     * @param  list<MemberChange>  $members
     * @param  list<array{name: string, kind: string, resolvable: bool, start: int, end: int}>  $headMembers
     * @return list<string>
     */
    private static function sourceFindings(array $members, array $headMembers, string $headSrc, ?EagerLoadStringChecker $eagerLoadChecker, ?FeatureGateChecker $featureGateChecker, ?InertiaPageChecker $inertiaPageChecker): array
    {
        if ($members === []) {
            return [];
        }

        $changedRanges = self::changedMemberRanges($members, $headMembers);

        return [
            ...($eagerLoadChecker ?? new EagerLoadStringChecker())->findingsFor($headSrc),
            ...($featureGateChecker ?? new FeatureGateChecker())->findingsFor($headSrc, $changedRanges),
            ...($inertiaPageChecker ?? new InertiaPageChecker())->findingsFor($headSrc, $changedRanges),
        ];
    }

    /**
     * The head-side line spans of the changed members, or null (whole file) when a class-level
     * change means no member span can bound the edit.
     *
     * @param  list<MemberChange>  $members
     * @param  list<array{name: string, kind: string, resolvable: bool, start: int, end: int}>  $headMembers
     * @return list<array{int, int}>|null
     */
    private static function changedMemberRanges(array $members, array $headMembers): ?array
    {
        $ranges = [];

        foreach ($members as $member) {
            if ($member->kind === MemberChange::KIND_CLASS) {
                return null;
            }

            foreach ($headMembers as $headMember) {
                if ($headMember['name'] === $member->name && $headMember['kind'] === $member->kind) {
                    $ranges[] = [$headMember['start'], $headMember['end']];
                }
            }
        }

        return $ranges;
    }

    /**
     * A brand-new member is additive; so is an addition-only edit to a model's `$fillable`/`$casts`/
     * `casts()` — harmless to existing code, never a coarse modification.
     *
     * @param  array{name: string, kind: string, resolvable: bool, start: int, end: int}  $member
     */
    private static function changeTypeFor(bool $existedBefore, string $headSrc, ?string $baseSrc, array $member): string
    {
        if (! $existedBefore) {
            return MemberChange::CHANGE_ADDED;
        }

        $additionOnlyConfigEdit = $baseSrc !== null
            && EloquentConfig::isConfigMember($member['name'], $member['kind'])
            && EloquentConfig::isAdditionOnlyEdit($headSrc, $baseSrc, $member['name'], $member['kind']);

        return $additionOnlyConfigEdit ? MemberChange::CHANGE_ADDED : MemberChange::CHANGE_MODIFIED;
    }

    private static function coarseClassChange(): MemberChange
    {
        return new MemberChange('', MemberChange::KIND_CLASS, MemberChange::CHANGE_MODIFIED, resolvable: false);
    }

    /**
     * A class declaration / attribute / modifier line changed (e.g. adding `final`): a `+`/`-`
     * line inside a class span but outside every member, that isn't whitespace-only.
     *
     * @param  array{added: list<array{line: int, text: string}>, removed: list<array{line: int, text: string}>}  $hunk
     * @param  array{parsed: bool, members: list<array{name: string, kind: string, resolvable: bool, start: int, end: int}>, classRanges: list<array{start: int, end: int}>}  $head
     * @param  array{parsed: bool, members: list<array{name: string, kind: string, resolvable: bool, start: int, end: int}>, classRanges: list<array{start: int, end: int}>}  $base
     */
    private static function hasClassLevelChange(array $hunk, array $head, array $base): bool
    {
        $addedOuter = self::outerLines($hunk['added'], $head['classRanges'], $head['members']);
        $removedOuter = self::outerLines($hunk['removed'], $base['classRanges'], $base['members']);

        if ($addedOuter === [] && $removedOuter === []) {
            return false;
        }

        return self::normalize($addedOuter, sort: true) !== self::normalize($removedOuter, sort: true);
    }

    /**
     * Lines inside a class span but outside every member (the class header / modifiers / `}`).
     *
     * @param  list<array{line: int, text: string}>  $lines
     * @param  list<array{start: int, end: int}>  $classRanges
     * @param  list<array{name: string, kind: string, resolvable: bool, start: int, end: int}>  $members
     * @return list<string>
     */
    private static function outerLines(array $lines, array $classRanges, array $members): array
    {
        $texts = [];

        foreach ($lines as $line) {
            $inClass = array_any($classRanges, fn (array $range): bool => $line['line'] >= $range['start'] && $line['line'] <= $range['end']);
            if (! $inClass) {
                continue;
            }

            foreach ($members as $member) {
                if ($line['line'] >= $member['start'] && $line['line'] <= $member['end']) {
                    continue 2;
                }
            }

            $texts[] = $line['text'];
        }

        return $texts;
    }

    /**
     * @param  array{added: list<array{line: int, text: string}>, removed: list<array{line: int, text: string}>}  $hunk
     * @param  array{name: string, kind: string, resolvable: bool, start: int, end: int}  $headMember
     * @param  array{name: string, kind: string, resolvable: bool, start: int, end: int}  $baseMember
     */
    private static function memberChangeIsCosmetic(array $hunk, array $headMember, array $baseMember): bool
    {
        $added = self::textsIn($hunk['added'], $headMember['start'], $headMember['end']);
        $removed = self::textsIn($hunk['removed'], $baseMember['start'], $baseMember['end']);

        return self::normalize($added) === self::normalize($removed);
    }

    /**
     * @param  list<array{line: int, text: string}>  $lines
     * @return list<string>
     */
    private static function textsIn(array $lines, int $start, int $end): array
    {
        $texts = [];

        foreach ($lines as $line) {
            if ($line['line'] >= $start && $line['line'] <= $end) {
                $texts[] = $line['text'];
            }
        }

        return $texts;
    }

    /**
     * Whitespace-stripped, order-preserving by default (a statement reorder in a body is a real
     * change). Pass `sort: true` for the class-header / import region, where a reorder is cosmetic.
     *
     * @param  list<string>  $texts
     */
    private static function normalize(array $texts, bool $sort = false): string
    {
        $normalized = array_map(static fn (string $text): string => (string) preg_replace('/\s+/', '', $text), $texts);

        if ($sort) {
            sort($normalized);
        }

        return implode("\n", $normalized);
    }

    /**
     * @param  list<array{name: string, kind: string, resolvable: bool, start: int, end: int}>  $members
     * @return array<string, array{name: string, kind: string, resolvable: bool, start: int, end: int}>
     */
    private static function byKey(array $members): array
    {
        $keyed = [];

        foreach ($members as $member) {
            $keyed[self::memberKey($member)] = $member;
        }

        return $keyed;
    }

    /** @param  array{name: string, kind: string, resolvable: bool, start: int, end: int}  $member */
    private static function memberKey(array $member): string
    {
        return $member['kind'] . '|' . $member['name'];
    }

    private static function baseSource(string $mergeBase, string $file, string $prefix): ?string
    {
        if ($mergeBase === '') {
            return null;
        }

        // The diff paths are base_path()-relative (via `--relative`); `git show {ref}:PATH` resolves
        // PATH against the repo root, so re-root it through {@see GitProjectPaths} (no-op at the root).
        $show = Process::path(base_path())->run(['git', 'show', '--end-of-options', "{$mergeBase}:" . GitProjectPaths::objectPath($prefix, $file)]);

        return $show->successful() ? $show->output() : null;
    }

    /** Null means the head source could not be read — the caller decides what that implies per hunk shape. */
    private static function headSource(string $head, string $file, string $prefix): ?string
    {
        if ($head === 'HEAD') {
            if (! is_file(base_path($file))) {
                return null;
            }

            $contents = file_get_contents(base_path($file));

            // A failed read (permissions, race with a checkout) must not read as an empty file —
            // empty classifies as cosmetic, the forbidden falsely-empty "no impact".
            return $contents === false ? null : $contents;
        }

        // See baseSource(): re-root the base_path()-relative path for `git show`.
        $show = Process::path(base_path())->run(['git', 'show', '--end-of-options', "{$head}:" . GitProjectPaths::objectPath($prefix, $file)]);

        return $show->successful() ? $show->output() : null;
    }
}
