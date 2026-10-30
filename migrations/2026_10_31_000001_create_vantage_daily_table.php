<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * The permanent history: one row per member, metric, day (or month, once old
 * days are merged) and breakdown, and only when the value is not zero.
 */
return Migration::createTable('vantage_daily', function (Blueprint $table) {
    $table->unsignedInteger('user_id');
    $table->string('metric', 8);
    $table->date('day');
    $table->char('span', 1)->default('d');
    $table->string('dim', 16)->default('');
    $table->unsignedInteger('value')->default(0);

    $table->primary(['user_id', 'metric', 'day', 'span', 'dim']);
    $table->index('day');
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
