<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\RequestFieldParser;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The request-parity lane's trigger. Every "yields nothing" case here is a deliberate silence: a
 * fabricated removal would name a field the reviewer then goes looking for in the wrong place.
 */
final class RequestFieldParserTest extends TestCase
{
    private const string PATH = 'app/Http/Requests/StorePostRequest.php';

    #[Test]
    public function it_diffs_the_literal_field_names_of_a_plain_rules_method(): void
    {
        $base = $this->request("'title' => ['required'],\n'subtitle' => ['nullable'],");
        $head = $this->request("'title' => ['required'],\n'sub_title' => ['nullable'],");

        $this->assertSame(
            [['subtitle'], ['sub_title']],
            RequestFieldParser::diffFor(self::PATH, isNew: false, headSrc: $head, baseSrc: $base),
        );
    }

    #[Test]
    public function a_rules_method_that_builds_its_array_up_yields_nothing(): void
    {
        // `$rules = [...]; if (…) …; return $rules;` cannot be enumerated. A reach limit, never a
        // wrong finding.
        $head = <<<'PHP'
            <?php
            namespace App\Http\Requests;
            final class StorePostRequest
            {
                public function rules(): array
                {
                    $rules = ['title' => ['required']];

                    return $rules;
                }
            }
            PHP;

        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: false, headSrc: $head, baseSrc: $this->request("'title' => ['required'],\n'subtitle' => [],")),
        );
    }

    #[Test]
    public function a_spread_in_the_rules_array_yields_nothing(): void
    {
        $head = $this->request("...\$this->shared(),\n'title' => ['required'],");

        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: false, headSrc: $head, baseSrc: $this->request("'title' => [],\n'subtitle' => [],")),
        );
    }

    #[Test]
    public function a_class_constant_key_yields_nothing_because_the_base_side_would_resolve_against_head(): void
    {
        $head = $this->request('self::FIELD => [],');

        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: false, headSrc: $head, baseSrc: $this->request("'subtitle' => [],")),
        );
    }

    #[Test]
    public function a_new_file_yields_nothing_because_no_consumer_sends_a_field_that_never_existed(): void
    {
        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: true, headSrc: $this->request("'title' => [],"), baseSrc: null),
        );

        // Asserted with a readable base too, so it is the new-file flag stopping this and not the
        // missing base source that always accompanies it in practice.
        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: true, headSrc: $this->request("'title' => [],"), baseSrc: $this->request("'title' => [],\n'subtitle' => [],")),
        );
    }

    #[Test]
    public function a_rules_method_outside_the_requests_directory_yields_nothing(): void
    {
        // The path is the whole convention: a `rules()` elsewhere is not addressed by a payload.
        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(
                'app/Services/RuleProvider.php',
                isNew: false,
                headSrc: $this->request("'title' => [],"),
                baseSrc: $this->request("'title' => [],\n'subtitle' => [],"),
            ),
        );
    }

    #[Test]
    public function a_dotted_field_name_survives_the_diff_verbatim(): void
    {
        // The checker is what decides a dotted name matches no consumer; the parser must not
        // silently reshape it into something that would.
        $this->assertSame(
            [['items.*.name'], []],
            RequestFieldParser::diffFor(
                self::PATH,
                isNew: false,
                headSrc: $this->request("'items' => ['array'],"),
                baseSrc: $this->request("'items' => ['array'],\n'items.*.name' => ['string'],"),
            ),
        );
    }

    #[Test]
    public function two_rules_methods_in_one_file_yield_nothing(): void
    {
        $head = <<<'PHP'
            <?php
            namespace App\Http\Requests;
            final class StorePostRequest
            {
                public function rules(): array
                {
                    return ['title' => []];
                }
            }
            final class Other
            {
                public function rules(): array
                {
                    return ['other' => []];
                }
            }
            PHP;

        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: false, headSrc: $head, baseSrc: $this->request("'title' => [],\n'subtitle' => [],")),
        );
    }

    private function request(string $rules): string
    {
        return <<<PHP
            <?php
            namespace App\\Http\\Requests;
            final class StorePostRequest
            {
                const string FIELD = 'title';

                public function rules(): array
                {
                    return [
                        {$rules}
                    ];
                }
            }
            PHP;
    }
}
