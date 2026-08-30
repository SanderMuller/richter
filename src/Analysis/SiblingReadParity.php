<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\SiblingReadIndex;
use SanderMuller\Richter\Changes\SiblingReads;
use SanderMuller\Richter\Support\RichterConfig;

/**
 * Advisory: a changed method reads a nullable column raw where the code that was already beside it
 * resolves the same value through a fallback.
 *
 * The value is simply absent at runtime — nothing throws, no test fails, and every other lane in this
 * package is silent, because the diff is internally consistent. It is not reach, not a removed guard
 * and not a payload key. Two production reviews found exactly this shape by hand.
 *
 * A FINDING, never a hazard, and that is settled rather than pending. A hazard says something BREAKS;
 * this proves only that two named sites treat one value differently, and the sibling may be the one
 * that is wrong. No sample size changes what the predicate proves, so it never reaches `risk`,
 * `--fail-on` or `affected-tests`.
 *
 * The wording follows from that: it names BOTH observed styles and claims nothing else. It must not
 * say the changed code "forgot the fallback" or "fails to handle null" — `=== null` in the changed
 * code is reported as a null-test, which is a different observation from a bare read, and the reader
 * needs the real one. One soft sibling is enough to report, because the finding names one comparison
 * and never a convention.
 *
 * @internal
 */
final readonly class SiblingReadParity
{
    /** @param  list<string>  $ignore  `App\Models\Order::external_id`, or a whole type */
    public function __construct(private SiblingReadIndex $index, private array $ignore) {}

    public static function fromConfig(?bool $enabledOverride = null): ?self
    {
        if (! ($enabledOverride ?? RichterConfig::siblingReadParityEnabled())) {
            return null;
        }

        return new self(SiblingReadIndex::forRun(), RichterConfig::siblingReadParityIgnore());
    }

    /**
     * @return list<string> advisory findings, both sides named
     */
    public function findingsFor(ChangedFileSymbols $file): array
    {
        $findings = [];

        foreach ($file->siblingReads as $key => $byStyle) {
            $styles = array_keys($byStyle);

            if (array_intersect($styles, SiblingReads::SOFT_STYLES) !== []) {
                continue;                       // the changed code already guards it
            }

            $source = $this->index->nullableScalars[$key] ?? null;

            if ($source === null || $this->isIgnored($key)) {
                continue;                       // not a nullable scalar column, or silenced
            }

            $soft = $this->softEvidenceExcluding($key, $file->fqcn);

            if ($soft === []) {
                continue;
            }

            $findings[] = $this->finding($key, $styles, $byStyle, $soft, $source, $file->fqcn);
        }

        sort($findings);

        return $findings;
    }

    /**
     * The soft styles some OTHER class reads this property with.
     *
     * The changed file's own base version is in the evidence index — every changed file's is, because
     * a model the same commit edits is still the code the changed method was written against. Its own
     * earlier reads are the one thing it cannot be graded against: a method comparing to another
     * method of the same class it just edited is the author's own work on both sides.
     *
     * @return list<string>
     */
    private function softEvidenceExcluding(string $key, string $ownClass): array
    {
        $soft = [];

        foreach ($this->index->evidence[$key] ?? [] as $style => $sites) {
            if (! in_array($style, SiblingReads::SOFT_STYLES, strict: true)) {
                continue;
            }

            foreach ($sites as $site) {
                if (explode('::', $site, 2)[0] !== $ownClass) {
                    $soft[] = $style;

                    break;
                }
            }
        }

        return $soft;
    }

    /**
     * @param  list<string>  $styles
     * @param  array<string, list<string>>  $byStyle
     * @param  list<string>  $soft
     */
    private function finding(string $key, array $styles, array $byStyle, array $soft, string $nullabilitySource, string $ownClass): string
    {
        [$type, $property] = explode('->', $key, 2);
        $sites = [];

        foreach ($byStyle as $where) {
            $sites = [...$sites, ...$where];
        }

        $evidence = [];

        foreach ($soft as $style) {
            foreach ($this->index->evidence[$key][$style] as $site) {
                if (explode('::', $site, 2)[0] !== $ownClass) {
                    $evidence[] = $site;
                }
            }
        }

        sort($styles);
        sort($soft);

        return sprintf(
            '%s reads %s->%s (%s); %s reads it (%s). Nullable per its %s — check whether this read needs the same handling.',
            $this->first($sites),
            class_basename($type),
            $property,
            implode('/', $styles),
            $this->first($evidence),
            implode('/', $soft),
            $nullabilitySource,
        );
    }

    /** @param  list<string>  $sites */
    private function first(array $sites): string
    {
        sort($sites);

        $named = $sites[0] ?? 'unknown';
        $rest = count(array_unique($sites)) - 1;

        return $rest > 0 ? sprintf('%s (and %d more)', $named, $rest) : $named;
    }

    private function isIgnored(string $key): bool
    {
        [$type] = explode('->', $key, 2);

        return in_array($key, $this->ignore, strict: true)
            || in_array(str_replace('->', '::', $key), $this->ignore, strict: true)
            || in_array($type, $this->ignore, strict: true);
    }
}
