<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * On MySQL/MariaDB servers where explicit_defaults_for_timestamp is off (XAMPP's MariaDB, for example),
 * the first NOT NULL timestamp column of a table silently gets ON UPDATE CURRENT_TIMESTAMP, so a value
 * such as an attempt's start time or a code's expiry would change whenever the row is updated.
 * This turns every such column (other than updated_at) into DATETIME with the same value, precision,
 * nullability and default, and without the automatic update. Values are kept; nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $columns = DB::select("SELECT TABLE_NAME AS t, COLUMN_NAME AS c, IS_NULLABLE AS n, COLUMN_DEFAULT AS d, DATETIME_PRECISION AS p
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp' AND LOWER(EXTRA) LIKE '%on update%' AND COLUMN_NAME <> 'updated_at'");
        foreach ($columns as $col) {
            $p = (int) $col->p;
            $type = $p > 0 ? "DATETIME($p)" : 'DATETIME';
            $null = $col->n === 'YES' ? 'NULL' : 'NOT NULL';
            $default = $col->d !== null && stripos((string) $col->d, 'current_timestamp') !== false
                ? ' DEFAULT CURRENT_TIMESTAMP'.($p > 0 ? "($p)" : '')
                : ($col->n === 'YES' ? ' DEFAULT NULL' : '');
            DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` %s %s%s', str_replace('`', '', $col->t), str_replace('`', '', $col->c), $type, $null, $default));
        }
    }

    public function down(): void
    {
        // nothing to undo: DATETIME keeps the same values without the automatic update
    }
};
