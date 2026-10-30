<?php

use Carbon\Carbon;
use Illuminate\Database\Schema\Builder;

/*
 * The day counting began, so a dashboard can say "Views since Nov 4" instead
 * of letting an empty start read as a quiet month.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        if (! $db->table('settings')->where('key', 'ernestdefoe-vantage.installed_on')->exists()) {
            $db->table('settings')->insert(['key' => 'ernestdefoe-vantage.installed_on', 'value' => Carbon::now('UTC')->toDateString()]);
        }
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->where('key', 'ernestdefoe-vantage.installed_on')->delete();
    },
];
