<?php

namespace ErnestDefoe\Vantage\Listener;

use ErnestDefoe\Vantage\Tracking\Recorder;
use ErnestDefoe\Vantage\Tracking\RequestHolder;
use ErnestDefoe\Vantage\Tracking\Visitor;
use Flarum\Discussion\Event\UserRead;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\User\User;

/**
 * 🚨 Members' impressions ride on a request Flarum already sends.
 *
 * As a member scrolls, the forum saves how far they have read. Core raises
 * `UserRead` only when that number goes UP, and after the save the model still
 * knows the number it had before. The posts between the two are exactly what
 * the member just read: no beacon, no extra request, and bots never send it.
 */
class CountReads
{
    /** Jumping to the end of a long discussion is not reading all of it. */
    private const MAX_RANGE = 20;

    public function __construct(private Recorder $recorder, private Visitor $visitor, private RequestHolder $holder)
    {
    }

    public function handle(UserRead $event): void
    {
        $state = $event->state;
        $old = (int) ($state->getPrevious()['last_read_post_number'] ?? 0);
        $new = (int) $state->last_read_post_number;

        if ($new <= $old) {
            return;
        }

        $request = $this->holder->request;
        $actor = $request ? RequestUtil::getActor($request) : null;

        // Only the reader whose read this is; never a stranger's preferences.
        if (! $actor || $actor->id !== (int) $state->user_id) {
            $actor = new User();
            $actor->id = (int) $state->user_id;
        }

        $reader = $this->visitor->for($actor, $request);

        if (! $reader) {
            return;
        }

        $authors = Post::query()
            ->where('discussion_id', $state->discussion_id)
            ->whereBetween('number', [max($old + 1, $new - self::MAX_RANGE + 1), $new])
            ->where('type', 'comment')
            ->whereNull('hidden_at')
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $state->user_id)
            ->pluck('user_id', 'id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->recorder->impressions($reader, $authors);
    }
}
