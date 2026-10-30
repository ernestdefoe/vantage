<?php

namespace ErnestDefoe\Vantage;

use Illuminate\Database\ConnectionInterface;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * The views under every post, in ONE query per request.
 *
 * The field getter queues its post and returns a closure; Flarum resolves
 * closures after every other field in the document, so the first one to run
 * loads the whole page of posts at once. Keyed by the request, never by a
 * long-lived property, so nothing leaks from one request to the next.
 */
class PostViews
{
    /** @var WeakMap<ServerRequestInterface, array{queued: array<int, true>, views: array<int, int>}> */
    private WeakMap $byRequest;

    public function __construct(private ConnectionInterface $db)
    {
        $this->byRequest = new WeakMap();
    }

    public function queue(ServerRequestInterface $request, int $postId): void
    {
        $state = $this->byRequest[$request] ?? ['queued' => [], 'views' => []];

        if (! isset($state['views'][$postId])) {
            $state['queued'][$postId] = true;
        }

        $this->byRequest[$request] = $state;
    }

    public function get(ServerRequestInterface $request, int $postId): int
    {
        $this->queue($request, $postId);
        $state = $this->byRequest[$request];

        if ($state['queued']) {
            $ids = array_keys($state['queued']);
            $found = $this->db->table('vantage_post_views')->whereIn('post_id', $ids)->pluck('views', 'post_id');

            foreach ($ids as $id) {
                $state['views'][$id] = (int) ($found[$id] ?? 0);
            }

            $state['queued'] = [];
            $this->byRequest[$request] = $state;
        }

        return $state['views'][$postId];
    }
}
