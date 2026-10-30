<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Counted events waiting for the rollup. Append-only, primary key only: one
 * multi-row insert per counted request is its whole write cost. The AUTHOR is
 * here, never the reader.
 */
return Migration::createTable('vantage_buffer', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('post_id')->nullable();
    $table->string('metric', 8);
    $table->dateTime('hour');
    $table->string('country', 2)->nullable();
    $table->string('age', 8)->nullable();
    $table->string('gender', 12)->nullable();
    $table->boolean('guest')->default(false);
    $table->unsignedInteger('n')->default(1);
});
