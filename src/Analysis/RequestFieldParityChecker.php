<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\CodeGraph;

/**
 * Advisory: a field this diff removed from a form request's `rules()`, still sent by a frontend
 * file that consumes one of the routes the request validates — the request-side counterpart of
 * {@see FrontendConsumerParityChecker}. Dropping a rule silently drops the field: it stops being
 * validated and stops appearing in `validated()`, so the value simply never arrives and nothing
 * anywhere reports an error.
 *
 * Findings only — never `risk`, `--fail-on`, or `affected-tests`. The match is send-shaped and
 * name-based, so a finding is evidence to check, not a verdict; the `RequestFqcn::field` ignore
 * form is the escape hatch for a false positive.
 *
 * A dotted rule key (`items.*.name`, `address.city`) matches nothing and is silently skipped. Its
 * segments appear separately in a payload, and matching the last one would fire on every unrelated
 * `city` in the file — the same no-guess trade the rest of the parity family makes.
 */
final readonly class RequestFieldParityChecker
{
    private FrontendConsumerLane $lane;

    /** @param  list<string>  $ignore  request FQCN or `RequestFqcn::field` entries, from richter.payload_parity.ignore */
    public function __construct(CodeGraph $graph, array $ignore = [], ?string $projectRoot = null, ?FrontendConsumerIndex $index = null)
    {
        $this->lane = new FrontendConsumerLane($graph, $ignore, $projectRoot, $index);
    }

    /**
     * @param  list<string>  $removedFields
     * @param  list<string>  $addedFields
     * @return list<string> advisory findings, consumer-file- and route-named
     */
    public function findingsFor(string $requestFqcn, array $removedFields, array $addedFields): array
    {
        if ($this->lane->isIgnored($requestFqcn)) {
            return [];
        }

        $ignoredFields = $this->lane->ignoredKeysFor($requestFqcn);
        $removed = array_values(array_diff($removedFields, $ignoredFields));
        $added = array_values(array_diff($addedFields, $ignoredFields));

        if ($removed === []) {
            return [];
        }

        $findings = [];

        foreach ($this->lane->routesUpstreamOf($requestFqcn) as $route) {
            foreach ($this->lane->filesReferencing($route) as $file) {
                foreach ($removed as $field) {
                    if ($this->sendsField($file, $field)) {
                        $findings[] = sprintf(
                            "%s posts to %s and sends '%s', which this diff removes from %s::rules()%s",
                            $file,
                            FrontendConsumerLane::routeLabel($route),
                            $field,
                            $requestFqcn,
                            FrontendConsumerLane::renameSuffix($removed, $added),
                        );
                    }
                }
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * Send-shaped only: an object-literal key (`{ title: … }`, `{ 'title': … }`, the `{ title }`
     * shorthand), a `FormData`/`URLSearchParams` `append`/`set` with the name as a string literal,
     * a bracket write (`payload['title'] = …`), and a dotted assignment (`form.title = …`).
     *
     * The object-literal pattern is the one the response-side lane names as its own false-positive
     * class — a destructure of a RESPONSE takes the same shape as an object literal being built.
     * Here it is the primary signal rather than the accident, which is why the two lanes match
     * separately instead of sharing one predicate. The residual cost is the mirror image: a file
     * that both posts to and reads from the endpoint can match on a field it only reads. The
     * advisory framing and the `RequestFqcn::field` ignore entry carry it.
     */
    private function sendsField(string $file, string $field): bool
    {
        $content = $this->lane->content($file);
        $quoted = preg_quote($field, '/');

        return preg_match('/[{,]\s*[\'"]?' . $quoted . '[\'"]?\s*[,}:]/', $content) === 1
            || preg_match('/\.(?:append|set)\(\s*[\'"]' . $quoted . '[\'"]/', $content) === 1
            || preg_match('/\[\s*[\'"]' . $quoted . '[\'"]\s*\]\s*=[^=]/', $content) === 1
            || preg_match('/\.' . $quoted . '\s*=[^=]/', $content) === 1;
    }
}
