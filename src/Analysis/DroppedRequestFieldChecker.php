<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Analysis\Hazards\HazardSource;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\RouterRouteIndex;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\RichterConfig;

/**
 * Advisory: a field a changed frontend file stopped sending, which the validation behind one of the
 * routes it calls still requires — the frontend-side counterpart of {@see RequestFieldParityChecker}.
 * The request then fails validation for every user, and the diff that caused it touched no PHP.
 *
 * A FINDING, never a hazard: a frontend edit does not move the risk level, and the routes it touches
 * join the report after the graded set is frozen. The same break read from the backend side is the
 * request lane's tier-2 hazard.
 *
 * Routes reach their own action through {@see RouterRouteIndex::actionsFor()}. From the action,
 * Brain's `action-to-form-request` edge names the form request, and the action's own body holds any
 * inline validation.
 *
 * Silent wherever either side cannot vouch: a head that spreads its payload ({@see SentFields::opaque()}),
 * a rule the source cannot read, a conditional rule, a request that rewrites its input before
 * validating, and a route the router knows but the graph does not.
 *
 * @internal
 */
final class DroppedRequestFieldChecker
{
    private const array INPUT_REWRITING_METHODS = ['prepareForValidation', 'validationData'];

    private ?RouterRouteIndex $routes = null;

    private bool $routesBuilt = false;

    /** @var array<string, array<string, bool|null>> "node\0method" => field => required */
    private array $requiredCache = [];

    public function __construct(
        private readonly CodeGraph $graph,
        private readonly FrontendConsumerLane $lane,
        private readonly string $projectRoot,
    ) {}

    /** @param  bool|null  $enabledOverride  the command's `--no-payload-parity`; null defers to config */
    public static function fromConfig(CodeGraph $graph, ?bool $enabledOverride = null): ?self
    {
        if (! ($enabledOverride ?? RichterConfig::payloadParityEnabled())) {
            return null;
        }

        return new self($graph, new FrontendConsumerLane($graph, RichterConfig::payloadParityIgnore()), base_path());
    }

    /** @return list<string> advisory findings, route- and validation-site-named; the caller prefixes the file */
    public function findingsFor(ChangedFileSymbols $file): array
    {
        if ($file->droppedSentFields === [] || ! $this->routes() instanceof RouterRouteIndex) {
            return [];
        }

        $findings = [];

        foreach ($file->droppedSentFields as $route => $dropped) {
            foreach ($this->routes()->actionsFor($route) as $action) {
                foreach ($this->sitesFor($action) as $site => $required) {
                    foreach ($this->droppedAndRequired($dropped, $site, $required) as $field) {
                        $findings[] = sprintf(
                            "no longer sends '%s' to %s, which %s still requires",
                            $field,
                            FrontendConsumerLane::routeLabel($route),
                            str_contains($site, '::') ? $site : "{$site}::rules()",
                        );
                    }
                }
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * Every validation site behind one action, each with its fields' required state: the form
     * requests the action type-hints (keyed on the request class), and the action's own inline
     * validation (keyed on the member that declares it, a parent's for an inherited action). Both
     * are the keys the request lane already uses, so one ignore entry covers both directions.
     *
     * @return array<string, array<string, bool|null>>
     */
    private function sitesFor(string $action): array
    {
        $sites = [];

        foreach ($this->graph->outgoingTargetsOfType([$action], 'action-to-form-request') as $target) {
            $request = explode('::', $target, 2)[0];
            // The class node, or the `validated` node Brain drew the edge to — whichever the graph placed.
            $source = $this->sourceOf($request) ?? $this->sourceOf($target);

            if ($source === null || $this->rewritesInput($source)) {
                continue;
            }

            $sites[$request] = $this->required($request, $source, 'rules');
        }

        $source = $this->sourceOf($action);
        $method = explode('::', $action, 2)[1] ?? '';
        $declaring = $source === null ? null : ValidationRules::memberNamed($source, $method);

        if ($source !== null && $declaring !== null) {
            $sites[$declaring] = $this->required($declaring, $source, $method);
        }

        return $sites;
    }

    /**
     * @param  list<string>  $dropped
     * @param  array<string, bool|null>  $required
     * @return list<string>
     */
    private function droppedAndRequired(array $dropped, string $site, array $required): array
    {
        if ($this->lane->isIgnored($site)) {
            return [];
        }

        $fields = FrontendConsumerLane::matchable($dropped, $this->lane->ignoredKeysFor($site));

        return array_values(array_filter($fields, static fn (string $field): bool => ($required[$field] ?? null) === true));
    }

    /** @return array<string, bool|null> */
    private function required(string $node, string $source, string $method): array
    {
        return $this->requiredCache["{$node}\0{$method}"] ??= ValidationRules::requiredFields($source, $method, requestDataOnly: true);
    }

    private function rewritesInput(string $source): bool
    {
        return array_any(
            array_keys(HazardSource::methods($source)),
            static fn (string $member): bool => in_array(explode('::', $member, 2)[1] ?? '', self::INPUT_REWRITING_METHODS, true),
        );
    }

    /**
     * Today's tree, as the graph sees it: under a named `--head` the validation side is today's code,
     * and the frontend side is that commit's.
     */
    private function sourceOf(string $node): ?string
    {
        $file = $this->graph->locationOf($node)['file'] ?? null;

        if ($file === null) {
            return null;
        }

        $path = str_starts_with($file, '/') ? $file : "{$this->projectRoot}/{$file}";
        $source = is_file($path) ? file_get_contents($path) : false;

        return $source === false ? null : $source;
    }

    private function routes(): ?RouterRouteIndex
    {
        if (! $this->routesBuilt) {
            $this->routes = RouterRouteIndex::fromRouter();
            $this->routesBuilt = true;
        }

        return $this->routes;
    }
}
