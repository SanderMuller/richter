<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;

/**
 * The row changes one raw SQL statement makes, as far as it is written out. Split from
 * {@see MigrationDataOperations} because reading SQL and reading a call chain share nothing.
 *
 * Not a SQL parser. Each statement is recognised by the verb it starts with, so a statement behind a
 * common table expression draws nothing. A value built at runtime is a gap in the text. A gap where the
 * table name belongs leaves the table unread, and a gap anywhere else changes nothing.
 *
 * @internal
 */
final class MigrationSql
{
    /** Stands where a runtime value was. Not a character an identifier can hold. */
    private const string GAP = "\0";

    /** @var array<string, string> the pattern a statement starts with => what it does to the rows */
    private const array STATEMENTS = [
        '/^update\s+(?:low_priority\s+|ignore\s+|only\s+)*(\S+)/i' => 'updated',
        '/^delete\s+(?:[^;]*?\s)??from\s+(?:only\s+)?(\S+)/i' => 'deleted',
        '/^truncate\s+(?:table\s+)?(?:only\s+)?(\S+)/i' => 'deleted',
        '/^replace\s+(?:low_priority\s+|delayed\s+)?into\s+(\S+)/i' => 'upserted',
        '/^insert\s+(?:ignore\s+)?into\s+([^\s(]+).*\bon\s+(?:duplicate\s+key\s+update|conflict\b.*\bdo\s+update)\b/is' => 'upserted',
    ];

    /** @return list<array{verb: string, table: string|null}> */
    public static function changes(Expr $sql): array
    {
        $changes = [];

        // `unprepared()` takes several statements at once.
        foreach (explode(';', self::text($sql)) as $statement) {
            $statement = (string) preg_replace('~^(?:\s+|--[^\n]*(?:\n|$)|/\*.*?\*/)+~s', '', $statement);

            foreach (self::STATEMENTS as $pattern => $verb) {
                if (preg_match($pattern, $statement, $match) === 1) {
                    $changes[] = ['verb' => $verb, 'table' => self::table($match[1])];

                    break;
                }
            }
        }

        return $changes;
    }

    private static function text(Expr $expr): string
    {
        return match (true) {
            $expr instanceof String_ => $expr->value,
            $expr instanceof Concat => self::text($expr->left) . self::text($expr->right),
            $expr instanceof InterpolatedString => implode('', array_map(
                static fn (Expr|InterpolatedStringPart $part): string => $part instanceof InterpolatedStringPart ? $part->value : self::GAP,
                $expr->parts,
            )),
            default => self::GAP,
        };
    }

    /** The bare table of a possibly quoted and schema-qualified name, or null where part of it is a gap. */
    private static function table(string $written): ?string
    {
        if (str_contains($written, self::GAP)) {
            return null;
        }

        $segments = explode('.', str_replace(['`', '"', '[', ']', ',', '(', ')'], '', $written));
        $table = end($segments);

        return $table === '' ? null : $table;
    }
}
