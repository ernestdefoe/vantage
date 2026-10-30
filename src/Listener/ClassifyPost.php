<?php

namespace ErnestDefoe\Vantage\Listener;

use ErnestDefoe\Vantage\PostType;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;

class ClassifyPost
{
    public function __construct(private PostType $types)
    {
    }

    public function handle(Posted|Revised $event): void
    {
        $this->types->store($event->post);
    }
}
