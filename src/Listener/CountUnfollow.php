<?php

namespace ErnestDefoe\Vantage\Listener;

use ErnestDefoe\Vantage\Tracking\Recorder;

/** Registered only when Follow Users is enabled (see extend.php). */
class CountUnfollow
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function handle(object $event): void
    {
        if (isset($event->user->id)) {
            $this->recorder->unfollow((int) $event->user->id);
        }
    }
}
