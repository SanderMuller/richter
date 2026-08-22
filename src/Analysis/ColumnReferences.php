<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\EloquentConfig;
use SanderMuller\Richter\Graph\CodeGraph;

/**
 * What still names a column a migration dropped or renamed. The destructive half of the migration lane
 * says the column is gone; this says who has not been told.
 *
 * Evidence only. A hazard's tier and reach are decided from the diff, and a reference found here never
 * moves either — it adds a sentence naming where to look. That is also why a surface this cannot read
 * is skipped in silence: the hazard has already fired, so a missed reference under-informs rather than
 * letting a destructive change go unreported.
 *
 * Two surfaces, both already parsed elsewhere:
 *
 * - The owning model's own `$fillable`/`$casts` ({@see EloquentConfig::fieldSet()}). Exact, with no
 *   association to guess: the model is the hazard's member. A dropped column still listed here is a
 *   mass-assignment or cast pointing at nothing.
 * - The resources that belong to that model ({@see ModelResources}), and that mirror it: a caller may
 *   touch several models, so a wired resource has to share fields with this model before its keys
 *   count. `toArray()` KEYS are what is compared, so a match means the resource still carries a key by
 *   that name — not that it reads the column. The evidence says so.
 *
 * A LOOKUP, not an index. Given one model and one column it costs one graph query and a file read per
 * candidate; nothing walks the whole tree except {@see ModelResources}'s name fallback, which memoizes.
 * Building an index over every column in the project would be work spent on columns no diff touched.
 *
 * Form requests are deliberately absent. There is no model-to-request association in the graph, and the
 * name-based one would be the weakest evidence here rather than the strongest.
 *
 * @internal
 */
final class ColumnReferences
{
    /** @var array<string, list<string>|null> path => resolved toArray() keys, null meaning unreadable */
    private array $keysCache = [];

    /** @var array<string, list<string>> model FQCN => the fields it declares */
    private array $fieldsCache = [];

    private readonly ModelResources $resources;

    /** @param  string|null  $projectRoot  overrides base_path() for tests; sources are read relative to it */
    public function __construct(
        private readonly CodeGraph $graph,
        private readonly ?string $projectRoot = null,
    ) {
        $this->resources = new ModelResources($graph, $projectRoot);
    }

    /**
     * Every hazard that names a field, told what still refers to it.
     *
     * @param  list<Hazard>  $hazards
     * @return list<Hazard>
     */
    public function attach(array $hazards): array
    {
        return array_map(function (Hazard $hazard): Hazard {
            if ($hazard->lane !== 'migration' || $hazard->field === null) {
                return $hazard;
            }

            $naming = $this->surfacesNaming($hazard->member, $hazard->field);

            return $naming === []
                ? $hazard
                : $hazard->withEvidence($hazard->evidence . ', still named by ' . implode(', ', $naming));
        }, $hazards);
    }

    /**
     * @return list<string> each entry already says which surface it is, so the sentence reads as one
     */
    private function surfacesNaming(string $model, string $column): array
    {
        // A table no model claimed leaves the bare table name as the member. It matches no graph node,
        // so both lookups below answer nothing on their own — no guard needed, and none wanted: a
        // guard here would have to know how a member is spelled, which is the reach lane's business.
        $surfaces = [];

        if ($this->modelDeclares($model, $column)) {
            $surfaces[] = "{$model}'s own \$fillable/\$casts";
        }

        foreach ($this->resourcesCarrying($model, $column) as $path) {
            $surfaces[] = "a `{$column}` key in {$path}";
        }

        return $surfaces;
    }

    /** Whether the model still lists the column among the fields it exposes to mass assignment or casting. */
    private function modelDeclares(string $model, string $column): bool
    {
        return in_array($column, $this->modelFields($model), strict: true);
    }

    /**
     * The model's own field union, empty where the model names no file richter can read.
     *
     * @return list<string>
     */
    private function modelFields(string $model): array
    {
        if (array_key_exists($model, $this->fieldsCache)) {
            return $this->fieldsCache[$model];
        }

        $location = $this->graph->locationOf($model);
        $source = $location === null ? null : $this->read($location['file']);

        return $this->fieldsCache[$model] = $source === null ? [] : EloquentConfig::fieldSet($source);
    }

    /**
     * The key alone is not evidence. A caller may touch several models and return several resources, so
     * a wired candidate can belong to a different model entirely — a controller reading `Post` and
     * returning a `User` resource would answer for a dropped `posts.id` on the strength of the name
     * `id`. The candidate has to mirror the model as well, which is the gate
     * {@see PayloadParityChecker} applies for the same reason, with the same minimum: one shared field
     * where wiring produced the candidate, two where only a name did.
     *
     * @return list<string> the resource paths whose `toArray()` still carries a key of that name
     */
    private function resourcesCarrying(string $model, string $column): array
    {
        ['candidates' => $candidates, 'viaGraph' => $viaGraph] = $this->resources->candidatesFor($model);
        $mirrored = array_values(array_diff($this->modelFields($model), [$column]));
        $minimumShared = $viaGraph ? 1 : 2;

        $paths = [];

        foreach ($candidates as $candidate) {
            $keys = $this->keysFor($candidate['path']);

            if ($keys === null || ! in_array($column, $keys, strict: true)) {
                continue;
            }

            if (count(array_intersect($mirrored, $keys)) >= $minimumShared) {
                $paths[] = $candidate['path'];
            }
        }

        return $paths;
    }

    /**
     * Non-strict, unlike the parity lane's read of the same file. Parity asks which keys a resource is
     * accountable for and must not over-claim; this asks whether one key is present, and a resource
     * with a dynamic key elsewhere still answers that honestly.
     *
     * @return list<string>|null
     */
    private function keysFor(string $path): ?array
    {
        if (array_key_exists($path, $this->keysCache)) {
            return $this->keysCache[$path];
        }

        $source = $this->read($path);

        return $this->keysCache[$path] = $source === null ? null : ResourceKeyParser::keysOf($source);
    }

    /** Null when the path names nothing readable — an uncheckable surface, never a guessed one. */
    private function read(string $path): ?string
    {
        $absolute = str_starts_with($path, '/') ? $path : rtrim($this->projectRoot ?? base_path(), '/') . "/{$path}";

        return is_file($absolute) ? (string) file_get_contents($absolute) : null;
    }
}
