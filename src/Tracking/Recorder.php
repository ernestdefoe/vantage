<?php

namespace ErnestDefoe\Vantage\Tracking;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

/**
 * The only writer of counted events. Everything a request counts goes into
 * `vantage_buffer` as one multi-row insert, and nothing else happens in the
 * request: no reads, no cache entries. Deduplication (once per reader, item
 * and day) and the guest caps are the rollup's job, set-based.
 */
class Recorder
{
    public const IMPRESSION = 'imp';
    public const REACH = 'rd';
    public const PROFILE = 'pv';
    public const SHARE = 'share';
    public const UNFOLLOW = 'unf';

    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * Posts read or seen: one impression each, and one "reached" per author.
     *
     * @param array<int, int> $authors post id => author id
     */
    public function impressions(Reader $reader, array $authors): int
    {
        $rows = [];

        foreach ($authors as $postId => $authorId) {
            $rows[] = $this->row($reader, $authorId, self::IMPRESSION, $postId);
        }

        foreach (array_unique(array_values($authors)) as $authorId) {
            $rows[] = $this->row($reader, $authorId, self::REACH);
        }

        $this->insert($rows);

        return count($authors);
    }

    public function profileVisit(Reader $reader, int $userId): void
    {
        $this->insert([$this->row($reader, $userId, self::PROFILE)]);
    }

    public function share(Reader $reader, int $authorId, int $postId): void
    {
        $this->insert([$this->row($reader, $authorId, self::SHARE, $postId)]);
    }

    /** Unfollows aren't kept anywhere else: Follow Users deletes the row. */
    public function unfollow(int $userId): void
    {
        $this->insert([[
            'user_id' => $userId, 'post_id' => null, 'metric' => self::UNFOLLOW,
            'hour' => Carbon::now('UTC')->format('Y-m-d H:00:00'),
            'country' => null, 'age' => null, 'gender' => null, 'guest' => false, 'n' => 1,
            'reader' => null, 'src' => null,
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
            'reader' => $reader->key,
            'src' => $reader->source,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function insert(array $rows): void
    {
        if ($rows) {
            $this->db->table('vantage_buffer')->insert($rows);
        }
    }
}
