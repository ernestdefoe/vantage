<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // Lifetime views of one post: the free number under every post.
        $schema->create('vantage_post_views', function (Blueprint $table) {
            $table->unsignedInteger('post_id')->primary();
            $table->unsignedInteger('views')->default(0);
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
        });

        // Text, image or video, decided once when the post is saved.
        $schema->create('vantage_post_types', function (Blueprint $table) {
            $table->unsignedInteger('post_id')->primary();
            $table->string('type', 8);
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('vantage_post_types');
        $schema->dropIfExists('vantage_post_views');
    },
];
