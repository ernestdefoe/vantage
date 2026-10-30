<?php

namespace ErnestDefoe\Vantage\Report;

use Illuminate\Database\ConnectionInterface;

final class Sql
{
    /**
     * "YYYY-MM-DD HH" of a timestamp column, per driver. The column goes
     * through the grammar so a table prefix is applied (`dev_post_likes`).
     */
    public static function hour(ConnectionInterface $db, string $column): string
    {
        $col = $db->getQueryGrammar()->wrap($column);

        return match ($db->getDriverName()) {
            'pgsql' => "to_char($col, 'YYYY-MM-DD HH24')",
            'sqlite' => "strftime('%Y-%m-%d %H', $col)",
            default => "DATE_FORMAT($col, '%Y-%m-%d %H')",
        };
    }
}
