<?php

namespace App\Support\Database;

use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * Migration helpers for enum-backed varchar columns. Enum columns are plain
 * varchar + a CHECK constraint (02-data-model §2) rather than native Postgres
 * enum types, so they are easy to evolve. Building the CHECK from the enum's
 * own cases keeps DB and PHP in lockstep.
 *
 * NOTE: these build raw SQL with no escaping — pass only developer-controlled
 * table/column names and enum class strings, never user-derived input.
 */
final class Constraints
{
    /**
     * Add a CHECK constraint restricting `$column` to the enum's string values.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function enumCheck(string $table, string $column, string $enum): void
    {
        $values = implode("','", array_map(fn (BackedEnum $c) => (string) $c->value, $enum::cases()));

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ('{$values}'))");
    }
}
