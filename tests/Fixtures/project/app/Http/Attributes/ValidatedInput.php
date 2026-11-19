<?php declare(strict_types=1);

namespace App\Http\Attributes;

use Attribute;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ValidatedInput implements ContextualAttribute
{
    /** @param  class-string  $request */
    public function __construct(public string $request) {}

    public static function resolve(self $attribute, Container $container): mixed
    {
        return $container->make($attribute->request);
    }
}
