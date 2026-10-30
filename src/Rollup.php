<?php

namespace ErnestDefoe\Vantage;

use Carbon\Carbon;
use ErnestDefoe\Vantage\Tracking\Recorder;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;

/**
 * Buffer → history. Set-based: one grouped read of the buffer for everyone,
 * merged into `vantage_daily` and `vantage_post_views` in batches, never one
 * query per member.
 *
 * Runs from the scheduler every 15 minutes, and again whenever a dashboard is
 * opened (under the same lock), so forums without cron still get numbers.
 */
class Rollup
{
    /** Buffer rows per pass; a pass on a busy forum stays a few seconds. */
    private const SLICE = 20000;

    /** Breakdown rows (hours, countries, age, gender, types) live this long. */
    public const DETAIL_DAYS = 90;

    /** Views one guest (an IP and browser) can add in a day… */
    public const GUEST_CAP = 400;

    /** …and one IP address, however many browsers it claims to be. */
    public const IP_CAP = 2000;

    public function __construct(
        private ConnectionInterface $db,
        private Cache $cache,
        private PostType $types,
        private Settings $settings,
        private SettingsRepositoryInterface $settingsRepo,
    ) {
    }

    /** @return int buffer rows folded in */
    public function run(int $maxSlices = 10): int
    {
        $store = $this->cache->getStore();
        $lock = $store instanceof LockProvider ? $store->lock('vantage:rollup', 300) : null;

        if ($lock && ! $lock->get()) {
            return 0;
        }

        try {
            // Type posts first, so a read of an old post lands in its kind.
            $this->types->backfill();
            $done = 0;

            for ($i = 0; $i < $maxSlices; $i++) {
                $n = $this->slice();
                $done += $n;

                if ($n < self::SLICE) {
                    break;
                }
            }

            $this->pruneDaily();

            return $done;
        } finally {
            $lock?->release();
        }
    }

    public function pending(): bool
    {
        return $this->db->table('vantage_buffer')->exists();
    }

    private function slice(): int
    {
        $min = $this->db->table('vantage_buffer')->min('id');

        if ($min === null) {
            return 0;
        }

        $max = (int) $min + self::SLICE - 1;

        $rows = $this->db->table('vantage_buffer')
            ->whereBetween('id', [(int) $min, $max])
            ->orderBy('id')
            ->get(['user_id', 'post_id', 'metric', 'hour', 'country', 'age', 'gender', 'guest', 'reader', 'src', 'n']);

        $accepted = $this->dedupe($rows->all());
        $types = $this->typesOf(array_unique(array_filter(array_map(fn ($row) => $row->post_id, $accepted))));
        $daily = [];
        $views = [];

        foreach ($accepted as $row) {
            $n = (int) $row->n;
            $hour = Carbon::parse($row->hour, 'UTC');
            $day = $hour->format('Y-m-d');
            $user = (int) $row->user_id;
            $metric = (string) $row->metric;

            $add = function (string $dim) use (&$daily, $user, $metric, $day, $n) {
                $key = "$user|$metric|$day|$dim";
                $daily[$key] = ($daily[$key] ?? 0) + $n;
            };

            $add('');
            $add('h:'.$hour->format('H'));

            if ($metric === Recorder::IMPRESSION) {
                if ($row->post_id) {
                    $views[(int) $row->post_id] = ($views[(int) $row->post_id] ?? 0) + $n;
                    $add('t:'.($types[(int) $row->post_id] ?? 'text'));
                }

                if ($row->guest) {
                    $add('v:g');
                }
            }

            // Audience breakdowns come from "reached", one per reader per day.
            if ($metric === Recorder::REACH || $metric === Recorder::PROFILE) {
                $add('c:'.($row->country ?: '--'));

                if ($metric === Recorder::REACH) {
                    $add('a:'.($row->age ?: '--'));
                    $add('g:'.($row->gender ?: '--'));
                }
            }
        }

        $this->db->transaction(function () use ($daily, $views, $min, $max) {
            $this->mergeDaily($daily, 'd');
            $this->mergeViews($views);
            $this->db->table('vantage_buffer')->whereBetween('id', [(int) $min, $max])->delete();
        });

        return $rows->count();
    }

    /**
     * Once per reader, item and day; and a guest (an IP and browser) or a
     * whole IP can only add so many views a day, so rotating the browser's
     * name doesn't inflate anyone's numbers.
     *
     * The keys already counted live in `vantage_seen`: read for this slice in
     * one query per thousand keys, written back in batches.
     *
     * @param list<object> $rows
     * @return list<object>
     */
    private function dedupe(array $rows): array
    {
        $keyOf = function (object $row): ?string {
            if (! $row->reader) {
                return null; // unfollows: counted as they come
            }

            $item = $row->metric === Recorder::IMPRESSION || $row->metric === Recorder::SHARE ? $row->post_id : $row->user_id;

            return substr(hash('sha256', $row->reader.'|'.$row->metric.'|'.$item), 0, 16);
        };

        $capKeys = fn (object $row) => $row->guest && $row->metric === Recorder::IMPRESSION
            ? [substr(hash('sha256', 'cap|'.$row->reader), 0, 16) => self::GUEST_CAP, substr(hash('sha256', 'capip|'.$row->src), 0, 16) => self::IP_CAP]
            : [];

        // Every key this slice could touch, per day.
        $wanted = [];

        foreach ($rows as $row) {
            $day = substr((string) $row->hour, 0, 10);

            if ($k = $keyOf($row)) {
                $wanted[$day][$k] = true;
            }

            foreach ($capKeys($row) as $k => $cap) {
                $wanted[$day][$k] = true;
            }
        }

        $seen = [];

        foreach ($wanted as $day => $keys) {
            foreach (array_chunk(array_keys($keys), 1000) as $chunk) {
                foreach ($this->db->table('vantage_seen')->where('day', $day)->whereIn('k', $chunk)->get(['k', 'n']) as $hit) {
                    $seen[$day][$hit->k] = (int) $hit->n;
                }
            }
        }

        $accepted = [];
        $touched = [];

        foreach ($rows as $row) {
            $day = substr((string) $row->hour, 0, 10);
            $k = $keyOf($row);

            if ($k !== null && isset($seen[$day][$k])) {
                continue;
            }

            $caps = $capKeys($row);

            foreach ($caps as $ck => $cap) {
                if (($seen[$day][$ck] ?? 0) >= $cap) {
                    continue 2;
                }
            }

            foreach ($caps as $ck => $cap) {
                $seen[$day][$ck] = ($seen[$day][$ck] ?? 0) + 1;
                $touched[$day][$ck] = true;
            }

            if ($k !== null) {
                $seen[$day][$k] = 1;
                $touched[$day][$k] = true;
            }

            $accepted[] = $row;
        }

        foreach ($touched as $day => $keys) {
            foreach (array_chunk(array_keys($keys), 500) as $chunk) {
                $this->db->table('vantage_seen')->upsert(
                    array_map(fn ($k) => ['day' => $day, 'k' => $k, 'n' => $seen[$day][$k]], $chunk),
                    ['day', 'k'],
                    ['n']
                );
            }
        }

        return $accepted;
    }

    /**
     * @param array<int, int> $postIds
     * @return array<int, string>
     */
    private function typesOf(array $postIds): array
    {
        $types = [];

        foreach (array_chunk($postIds, 1000) as $chunk) {
            foreach ($this->db->table('vantage_post_types')->whereIn('post_id', $chunk)->get(['post_id', 'type']) as $row) {
                $types[(int) $row->post_id] = (string) $row->type;
            }
        }

        return $types;
    }

    /**
     * Adds to existing rows. Read the rows these keys already have, add in
     * PHP, upsert the sums: portable across MySQL, PostgreSQL and SQLite,
     * where "value = value + excluded.value" is not.
     *
     * @param array<string, int> $sums "user|metric|day|dim" => value
     */
    private function mergeDaily(array $sums, string $span): void
    {
        foreach (array_chunk($sums, 500, true) as $chunk) {
            $users = $metrics = $days = [];

            foreach (array_keys($chunk) as $key) {
                [$u, $m, $d] = explode('|', $key, 4);
                $users[$u] = true;
                $metrics[$m] = true;
                $days[$d] = true;
            }

            $existing = $this->db->table('vantage_daily')
                ->whereIn('user_id', array_keys($users))
                ->whereIn('metric', array_keys($metrics))
                ->whereIn('day', array_keys($days))
                ->where('span', $span)
                ->get(['user_id', 'metric', 'day', 'dim', 'value']);

            foreach ($existing as $row) {
                $key = $row->user_id.'|'.$row->metric.'|'.substr((string) $row->day, 0, 10).'|'.$row->dim;

                if (isset($chunk[$key])) {
                    $chunk[$key] += (int) $row->value;
                }
            }

            $upsert = [];

            foreach ($chunk as $key => $value) {
                [$u, $m, $d, $dim] = explode('|', $key, 4);
                $upsert[] = ['user_id' => (int) $u, 'metric' => $m, 'day' => $d, 'span' => $span, 'dim' => $dim, 'value' => $value];
            }

            $this->db->table('vantage_daily')->upsert($upsert, ['user_id', 'metric', 'day', 'span', 'dim'], ['value']);
        }
    }

    /** @param array<int, int> $views */
    private function mergeViews(array $views): void
    {
        foreach (array_chunk($views, 500, true) as $chunk) {
            $existing = $this->db->table('vantage_post_views')->whereIn('post_id', array_keys($chunk))->pluck('views', 'post_id');

            // Only posts that still exist: a post deleted since it was read is gone.
            $alive = $this->db->table('posts')->whereIn('id', array_keys($chunk))->pluck('id')->flip();

            $upsert = [];

            foreach ($chunk as $postId => $n) {
                if (isset($alive[$postId])) {
                    $upsert[] = ['post_id' => $postId, 'views' => $n + (int) ($existing[$postId] ?? 0)];
                }
            }

            if ($upsert) {
                $this->db->table('vantage_post_views')->upsert($upsert, ['post_id'], ['views']);
            }
        }
    }

    /**
     * Once a day: breakdowns older than DETAIL_DAYS go, and day rows older
     * than the retention setting are merged into one row per month.
     */
    public function pruneDaily(bool $force = false): void
    {
        $today = Carbon::now('UTC')->toDateString();
        $key = Settings::PREFIX.'pruned_on';

        if (! $force && $this->settingsRepo->get($key) === $today) {
            return;
        }

        $this->settingsRepo->set($key, $today);

        $this->db->table('vantage_seen')->where('day', '<', Carbon::now('UTC')->subDay()->toDateString())->delete();

        $this->db->table('vantage_daily')
            ->where('dim', '!=', '')
            ->where('day', '<', Carbon::now('UTC')->subDays(self::DETAIL_DAYS)->toDateString())
            ->delete();

        $cutoff = Carbon::now('UTC')->subMonths($this->settings->retentionMonths())->startOfMonth()->toDateString();

        do {
            $old = $this->db->table('vantage_daily')
                ->where('span', 'd')
                ->where('day', '<', $cutoff)
                ->orderBy('user_id')->orderBy('metric')->orderBy('day')
                ->limit(5000)
                ->get(['user_id', 'metric', 'day', 'value']);

            $months = [];

            foreach ($old as $row) {
                $k = $row->user_id.'|'.$row->metric.'|'.substr((string) $row->day, 0, 7).'-01|';
                $months[$k] = ($months[$k] ?? 0) + (int) $row->value;
            }

            $this->db->transaction(function () use ($months, $old) {
                $this->mergeDaily($months, 'm');

                // Ordered by member, metric, day: each group's fetched rows are a
                // contiguous run of days, so a range deletes exactly those.
                foreach ($old->groupBy(fn ($row) => $row->user_id.'|'.$row->metric) as $rows) {
                    $first = $rows->first();

                    $this->db->table('vantage_daily')
                        ->where('user_id', $first->user_id)
                        ->where('metric', $first->metric)
                        ->where('span', 'd')
                        ->where('dim', '')
                        ->whereBetween('day', [$rows->min('day'), $rows->max('day')])
                        ->delete();
                }
            });
        } while ($old->count() === 5000);
    }
}
