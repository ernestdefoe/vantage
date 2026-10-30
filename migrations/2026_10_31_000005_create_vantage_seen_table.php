<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Today's and yesterday's "already counted" keys, for the rollup's dedupe
 * (this reader, this post, this day) and guest caps. Hashed keys only; rows
 * older than yesterday are deleted every day.
 *
 * 🚨 This replaced per-reader cache entries: Laravel's file cache never
 * deletes an expired file nobody reads again, so a busy forum on the default
 * file cache collected thousands of dead files a day.
 */
return Migration::createTable('vantage_seen', function (Blueprint $table) {
    $table->date('day');
    $table->char('k', 16);
    $table->unsignedInteger('n')->default(1);

    $table->primary(['day', 'k']);
});
