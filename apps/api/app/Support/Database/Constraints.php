<?php

namespace App\Support\Database;

use BackedEnum;

/**
 * Migration helpers for enum-backed varchar columns. Enum columns are plain
 * varchar + a CHECK constraint (02-data-model §2) rather than native Postgres
 * enum types, so they are easy to evolve. Building the CHECK from the enum's
 * own cases keeps DB and PHP in lockstep.
 */
final class Constraints
{
    /**
     * @param  array<int, BackedEnum>  $cases
     */
    public static function enumCheck(string $table, string $column, array $cases): string
    {
        $values = implode("','", array_map(fn (BackedEnum $c) => (string) $c->value, $cases));

        return "ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ('{$values}'))";
    }
}
