<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Advisory: a field this diff stopped validating, still sent by a frontend file that consumes one
 * of the routes behind it — the request-side counterpart of {@see FrontendConsumerParityChecker}.
 * Dropping a rule silently drops the field: it stops being validated and stops appearing in
 * `validated()`, so the value simply never arrives and nothing anywhere reports an error.
 *
 * The anchor is whatever held the rule, and the caller decides which: a form request's class for a
 * `rules()` change, the member holding the call for validation written inline. {@see
 * validationSite()} spells `rules()` out only for the former, since appending it to a member id
 * would name a symbol that does not exist.
 *
 * Findings only — never `risk`, `--fail-on`, or `affected-tests`. The match is send-shaped and
 * name-based, so a finding is evidence to check, not a verdict; the `Anchor::field` ignore form —
 * against the request class or the member — is the escape hatch for a false positive.
 *
 * A dotted rule key (`items.*.name`, `address.city`) matches nothing and is silently skipped. Its
 * segments appear separately in a payload, and matching the last one would fire on every unrelated
 * `city` in the file — the same no-guess trade the rest of the parity family makes.
 */
final readonly class RequestFieldParityChecker
{
    public function __construct(private FrontendConsumerLane $lane) {}

    /**
     * @param  string  $anchor  whatever held the rule: a form request's class, or the member id of
     *   a method that validates inline. Not always an FQCN, which is why it is not called one.
     * @param  list<string>  $removedFields
     * @param  list<string>  $addedFields
     * @return list<string> advisory findings, consumer-file- and route-named
     */
    public function findingsFor(string $anchor, array $removedFields, array $addedFields): array
    {
        if ($this->lane->isIgnored($anchor)) {
            return [];
        }

        $ignoredFields = $this->lane->ignoredKeysFor($anchor);
        $removed = FrontendConsumerLane::matchable($removedFields, $ignoredFields);
        $added = FrontendConsumerLane::matchable($addedFields, $ignoredFields);

        if ($removed === []) {
            return [];
        }

        $findings = [];

        foreach ($this->lane->routesUpstreamOf($anchor) as $route) {
            foreach ($this->lane->filesReferencing($route) as $file) {
                foreach ($removed as $field) {
                    if ($this->sendsField($file, $field)) {
                        $findings[] = sprintf(
                            "%s sends '%s' to %s, which this diff removes from %s%s",
                            $file,
                            $field,
                            FrontendConsumerLane::routeLabel($route),
                            $this->validationSite($anchor),
                            FrontendConsumerLane::renameSuffix($removed, $added),
                        );
                    }
                }
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * Where the rule lived, named so the reader can open it. A form request is anchored on its class,
     * so its `rules()` is spelled out; inline validation is already anchored on the member holding
     * the call, and appending `::rules()` there would name `PostController::store::rules()` — a
     * symbol that does not exist, and a wrong ignore target to copy out of the report.
     */
    private function validationSite(string $anchor): string
    {
        return str_contains($anchor, '::') ? $anchor : $anchor . '::rules()';
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
     * advisory framing and the `Anchor::field` ignore entry carry it.
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
