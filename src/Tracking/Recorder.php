<?php

namespace ErnestDefoe\Vantage\Tracking;

use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * The only writer of counted events. Everything a request counts goes into
 * `vantage_buffer` as one multi-row insert; the rollup turns it into history.
 *
 * Dedupe state for a reader is ONE cache entry per day (one read, one write),
 * whatever they did: which authors they already reached, which profiles they
 * already visited, which guest-seen posts were already counted.
 */
class Recorder
{
    public const IMPRESSION = 'imp';
    public const REACH = 'rd';
    public const PROFILE = 'pv';
    public const SHARE = 'share';
    public const UNFOLLOW = 'unf';

    /** A guest can't add more than this many impressions in a day. */
    public const GUEST_DAILY_CAP = 400;

    public function __construct(private ConnectionInterface $db, private Cache $cache)
    {
    }

    /**
     * Posts read or seen: one impression each, and one "reached" per author
     * the reader hadn't reached yet today.
     *
     * @param array<int, int> $authors post id => author id
     * @param int|null $discussionId set for guests: their seen posts are deduped per discussion
     */
    public function impressions(Reader $reader, array $authors, ?int $discussionId = null): int
    {
        if (! $authors) {
            return 0;
        }

        [$key, $state] = $this->state($reader);

        if ($reader->guest) {
            $seen = $state['d'][$discussionId] ?? [];
            $authors = array_diff_key($authors, array_flip($seen));
            $room = self::GUEST_DAILY_CAP - ($state['n'] ?? 0);
            $authors = array_slice($authors, 0, max(0, $room), true);

            if (! $authors) {
                return 0;
            }

            $state['d'][$discussionId] = array_merge($seen, array_keys($authors));
            $state['n'] = ($state['n'] ?? 0) + count($authors);
        }

        $rows = [];

        foreach ($authors as $postId => $authorId) {
            $rows[] = $this->row($reader, $authorId, self::IMPRESSION, $postId);
        }

        foreach (array_unique(array_values($authors)) as $authorId) {
            if (! in_array($authorId, $state['a'] ?? [], true)) {
                $state['a'][] = $authorId;
                $rows[] = $this->row($reader, $authorId, self::REACH);
            }
        }

        $this->insert($rows);
        $this->save($key, $state);

        return count($authors);
    }

    /** A visit to someone's profile, once per visitor per profile per day. */
    public function profileVisit(Reader $reader, int $userId): bool
    {
        [$key, $state] = $this->state($reader);

        if (in_array($userId, $state['p'] ?? [], true)) {
            return false;
        }

        $state['p'][] = $userId;
        $this->insert([$this->row($reader, $userId, self::PROFILE)]);
        $this->save($key, $state);

        return true;
    }

    /** A share-button click, once per sharer per post per day. */
    public function share(Reader $reader, int $authorId, int $postId): bool
    {
        [$key, $state] = $this->state($reader);

        if (in_array($postId, $state['s'] ?? [], true)) {
            return false;
        }

        $state['s'][] = $postId;
        $this->insert([$this->row($reader, $authorId, self::SHARE, $postId)]);
        $this->save($key, $state);

        return true;
    }

    /** Unfollows aren't kept anywhere else: Follow Users deletes the row. */
    public function unfollow(int $userId): void
    {
        $this->insert([[
            'user_id' => $userId, 'post_id' => null, 'metric' => self::UNFOLLOW,
            'hour' => Carbon::now('UTC')->format('Y-m-d H:00:00'),
            'country' => null, 'age' => null, 'gender' => null, 'guest' => false, 'n' => 1,
        ]]);
    }

    /** @return array<string, mixed> */
    private function row(Reader $reader, int $userId, string $metric, ?int $postId = null): array
    {
        return [
            'user_id' => $userId,
            'post_id' => $postId,
            'metric' => $metric,
            'hour' => Carbon::now('UTC')->format('Y-m-d H:00:00'),
            'country' => $reader->country,
            'age' => $reader->age,
            'gender' => $reader->gender,
            'guest' => $reader->guest,
            'n' => 1,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function insert(array $rows): void
    {
        if ($rows) {
            $this->db->table('vantage_buffer')->insert($rows);
        }
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function state(Reader $reader): array
    {
        $key = 'vantage:r:'.Carbon::now('UTC')->format('Ymd').':'.$reader->key;
        $state = $this->cache->get($key);

        return [$key, is_array($state) ? $state : []];
    }

    /** @param array<string, mixed> $state */
    private function save(string $key, array $state): void
    {
        $this->cache->put($key, $state, 60 * 60 * 26);
    }
}
