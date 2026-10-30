<?php

namespace ErnestDefoe\Vantage\Report;

use Carbon\Carbon;
use ErnestDefoe\Vantage\PostType;
use ErnestDefoe\Vantage\Rollup;
use ErnestDefoe\Vantage\Tracking\Recorder;
use Flarum\Extension\ExtensionManager;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * One member's dashboard for one rolling period.
 *
 * Counts that other tables already keep with a date (likes, replies,
 * reactions, bookmarks, follows, reposts) are read live from those tables,
 * so their history goes back to day one. Only what nothing else records
 * (impressions, profile visits, shares, unfollows, audience) comes from
 * Vantage's own rollup.
 *
 * Everything is grouped by UTC hour in SQL and folded into the viewer's own
 * days in PHP, so "Tuesday" means the viewer's Tuesday on every driver.
 */
class Report
{
    /** Rolling periods in days; 0 is "all time". */
    public const RANGES = [1, 7, 28, 90, 365, 0];

    /** What counts as an engagement. */
    public const ENGAGEMENTS = ['replies', 'likes', 'reactions', 'bookmarks', 'reposts', 'shares'];

    /** Every series a dashboard can draw. */
    public const SERIES = ['impressions', 'replies', 'likes', 'reactions', 'bookmarks', 'reposts', 'shares', 'profileVisits', 'follows', 'unfollows'];

    /** Below this many readers in a period, a country is shown as "Other". */
    public const COUNTRY_MIN = 5;

    /** Below this many, an age band or gender is shown as "Other"… */
    public const DEMOGRAPHIC_MIN = 10;

    /** …and below this many readers in all, the breakdown isn't shown at all. */
    public const AUDIENCE_MIN = 50;

    private int $user;

    public function __construct(
        private ConnectionInterface $db,
        private ExtensionManager $extensions,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function available(): array
    {
        return [
            'likes' => $this->extensions->isEnabled('flarum-likes'),
            'mentions' => $this->extensions->isEnabled('flarum-mentions'),
            'reactions' => $this->extensions->isEnabled('fof-reactions'),
            'bookmarks' => $this->extensions->isEnabled('fof-bookmarks'),
            'follows' => $this->extensions->isEnabled('ianm-follow-users'),
            'reposts' => $this->extensions->isEnabled('ernestdefoe-moments'),
        ];
    }

    /** @return array<string, mixed> */
    public function build(User $user, int $days, int $tz, ?User $viewer = null): array
    {
        $this->user = (int) $user->id;
        $tz = max(-840, min(840, $tz));
        $now = Carbon::now('UTC');
        $all = $days === 0;
        $joined = $user->joined_at ? Carbon::instance($user->joined_at)->utc() : $now->copy()->subYear();
        $start = $all ? $joined : $now->copy()->subDays($days);
        $prevStart = $all ? null : $start->copy()->subDays($days);
        $from = $prevStart ?? $start;
        $available = $this->available();

        // Every event as "Y-m-d H" (UTC) => count, per series.
        $hours = $this->sourceHours($from, $now, $available) + $this->ownHours($from, $now);

        $granularity = $all ? 'month' : 'day';
        $series = [];
        $current = array_fill_keys(self::SERIES, 0);
        $previous = array_fill_keys(self::SERIES, 0);
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $gridFrom = max($start->getTimestamp(), $now->getTimestamp() - Rollup::DETAIL_DAYS * 86400);

        foreach ($hours as $name => $byHour) {
            foreach ($byHour as $hour => $n) {
                $ts = Carbon::createFromFormat('Y-m-d H', $hour, 'UTC')->minute(0)->second(0)->getTimestamp();

                if ($ts < $start->getTimestamp()) {
                    if ($prevStart && $ts >= $prevStart->getTimestamp()) {
                        $previous[$name] += $n;
                    }

                    continue;
                }

                $current[$name] += $n;
                $local = Carbon::createFromTimestamp($ts + $tz * 60, 'UTC');
                $key = $granularity === 'month' ? $local->format('Y-m') : $local->format('Y-m-d');
                $series[$key][$name] = ($series[$key][$name] ?? 0) + $n;

                // When the audience is around: reads and engagements, not
                // the member's own follower list changing.
                if ($ts >= $gridFrom && ! in_array($name, ['follows', 'unfollows', 'profileVisits'], true)) {
                    $grid[(int) $local->format('w')][(int) $local->format('G')] += $n;
                }
            }
        }

        $engaged = fn (array $totals) => array_sum(array_intersect_key($totals, array_flip(self::ENGAGEMENTS)));

        return [
            'range' => $days,
            'from' => $start->toIso8601String(),
            'to' => $now->toIso8601String(),
            'granularity' => $granularity,
            'since' => $this->settings->get('ernestdefoe-vantage.installed_on'),
            'available' => $available,
            'totals' => $current,
            'previous' => $prevStart ? $previous : null,
            'tiles' => [
                'impressions' => [$current['impressions'], $prevStart ? $previous['impressions'] : null],
                'engagements' => [$engaged($current), $prevStart ? $engaged($previous) : null],
                'rate' => [$this->rate($engaged($current), $current['impressions']), $prevStart ? $this->rate($engaged($previous), $previous['impressions']) : null],
                'profileVisits' => [$current['profileVisits'], $prevStart ? $previous['profileVisits'] : null],
            ],
            'followers' => $available['follows'] ? $this->followers() : null,
            'series' => $this->fill($series, $start, $now, $tz, $granularity),
            'bestTimes' => $grid,
            'types' => $this->types($start, $available),
            'topPosts' => $this->topPosts($start, $now, $available, $viewer ?? $user),
            'audience' => $this->audience($start),
        ];
    }

    /** A column through the grammar, so a table prefix is applied in raw SQL. */
    private function wrap(string $column): string
    {
        return $this->db->getQueryGrammar()->wrap($column);
    }

    private function rate(int $engagements, int $impressions): ?float
    {
        return $impressions > 0 ? round($engagements / $impressions * 100, 2) : null;
    }

    /**
     * Each local day (or month) in the period, oldest first, with zeros where
     * nothing happened, so a chart's x-axis is the calendar, not the data.
     *
     * @param array<string, array<string, int>> $series
     * @return list<array<string, mixed>>
     */
    private function fill(array $series, Carbon $start, Carbon $end, int $tz, string $granularity): array
    {
        $cursor = Carbon::createFromTimestamp($start->getTimestamp() + $tz * 60, 'UTC')->startOfDay();
        $last = Carbon::createFromTimestamp($end->getTimestamp() + $tz * 60, 'UTC');
        $out = [];

        if ($granularity === 'month') {
            $cursor->startOfMonth();
        }

        while ($cursor <= $last && count($out) < 2000) {
            $key = $granularity === 'month' ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $out[] = ['key' => $key] + array_replace(array_fill_keys(self::SERIES, 0), $series[$key] ?? []);
            $granularity === 'month' ? $cursor->addMonth() : $cursor->addDay();
        }

        return $out;
    }

    /** @return array<string, int> "Y-m-d H" => count */
    private function hoursOf(Builder $query, string $column): array
    {
        $expr = Sql::hour($this->db, $column);

        return $query
            ->selectRaw("$expr as h, COUNT(*) as n")
            ->groupByRaw($expr)
            ->pluck('n', 'h')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * Engagements other tables already record, each with a date.
     *
     * @return array<string, array<string, int>>
     */
    private function sourceHours(Carbon $from, Carbon $to, array $available): array
    {
        $u = $this->user;
        $range = [$from->toDateTimeString(), $to->toDateTimeString()];
        $out = [];

        $replies = $this->db->table('posts')
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->whereNotNull('posts.user_id')
            ->where('posts.user_id', '!=', $u)
            ->whereBetween('posts.created_at', $range)
            ->where(function (Builder $q) use ($u, $available) {
                $q->where(function (Builder $q) use ($u) {
                    $q->where('posts.number', '>', 1)
                        ->whereIn('posts.discussion_id', $this->db->table('discussions')->select('id')->where('user_id', $u));
                });

                if ($available['mentions']) {
                    $q->orWhereIn('posts.id', $this->db->table('post_mentions_post')
                        ->select('post_mentions_post.post_id')
                        ->join('posts', 'posts.id', '=', 'post_mentions_post.mentions_post_id')
                        ->where('posts.user_id', $u));
                }
            });

        $out['replies'] = $this->hoursOf($replies, 'posts.created_at');

        if ($available['likes']) {
            $out['likes'] = $this->hoursOf($this->db->table('post_likes')
                ->join('posts', 'posts.id', '=', 'post_likes.post_id')
                ->where('posts.user_id', $u)
                ->where('post_likes.user_id', '!=', $u)
                ->whereBetween('post_likes.created_at', $range), 'post_likes.created_at');
        }

        if ($available['reactions']) {
            $out['reactions'] = $this->hoursOf($this->db->table('post_reactions')
                ->join('posts', 'posts.id', '=', 'post_reactions.post_id')
                ->where('posts.user_id', $u)
                ->where('post_reactions.user_id', '!=', $u)
                ->whereBetween('post_reactions.created_at', $range), 'post_reactions.created_at');
        }

        if ($available['bookmarks']) {
            $posts = $this->hoursOf($this->db->table('post_user_bookmark')
                ->join('posts', 'posts.id', '=', 'post_user_bookmark.post_id')
                ->where('posts.user_id', $u)
                ->where('post_user_bookmark.user_id', '!=', $u)
                ->whereBetween('post_user_bookmark.created_at', $range), 'post_user_bookmark.created_at');

            $discussions = $this->hoursOf($this->db->table('discussion_user')
                ->join('discussions', 'discussions.id', '=', 'discussion_user.discussion_id')
                ->where('discussions.user_id', $u)
                ->where('discussion_user.user_id', '!=', $u)
                ->whereBetween('discussion_user.bookmarked_at', $range), 'discussion_user.bookmarked_at');

            foreach ($discussions as $h => $n) {
                $posts[$h] = ($posts[$h] ?? 0) + $n;
            }

            $out['bookmarks'] = $posts;
        }

        if ($available['follows']) {
            $out['follows'] = $this->hoursOf($this->db->table('user_followers')
                ->where('followed_user_id', $u)
                ->whereBetween('created_at', $range), 'user_followers.created_at');
        }

        if ($available['reposts']) {
            $out['reposts'] = $this->hoursOf($this->db->table('moments_entries')
                ->whereIn('repost_of_id', $this->db->table('moments_entries')->select('id')->where('user_id', $u))
                ->where('user_id', '!=', $u)
                ->whereNull('hidden_at')
                ->whereBetween('created_at', $range), 'moments_entries.created_at');
        }

        return $out;
    }

    /**
     * Vantage's own counts. Hour rows exist for the last DETAIL_DAYS; older
     * history is a day (or month) row, placed at noon UTC so the viewer's
     * time zone keeps it on the same date.
     *
     * @return array<string, array<string, int>>
     */
    private function ownHours(Carbon $from, Carbon $to): array
    {
        $names = [Recorder::IMPRESSION => 'impressions', Recorder::PROFILE => 'profileVisits', Recorder::SHARE => 'shares', Recorder::UNFOLLOW => 'unfollows'];
        $out = array_fill_keys(array_values($names), []);
        $detailFrom = Carbon::now('UTC')->subDays(Rollup::DETAIL_DAYS - 1)->startOfDay();

        $rows = $this->db->table('vantage_daily')
            ->where('user_id', $this->user)
            ->whereIn('metric', array_keys($names))
            ->where('day', '>=', $from->copy()->startOfMonth()->toDateString())
            ->where(function (Builder $q) use ($detailFrom) {
                $q->where(fn (Builder $q) => $q->where('span', 'd')->where('dim', 'like', 'h:%')->where('day', '>=', $detailFrom->toDateString()))
                    ->orWhere(fn (Builder $q) => $q->where('span', 'd')->where('dim', '')->where('day', '<', $detailFrom->toDateString()))
                    ->orWhere('span', 'm');
            })
            ->get(['metric', 'day', 'span', 'dim', 'value']);

        foreach ($rows as $row) {
            $day = substr((string) $row->day, 0, 10);
            $hour = $row->dim === '' ? '12' : substr((string) $row->dim, 2, 2);
            $key = "$day $hour";
            $name = $names[$row->metric];
            $out[$name][$key] = ($out[$name][$key] ?? 0) + (int) $row->value;
        }

        return $out;
    }

    /** @return array<string, int> */
    private function followers(): array
    {
        $total = $this->db->table('user_followers')->where('followed_user_id', $this->user)->count();

        // "Verified" is a follower with a confirmed email address.
        $verified = $this->db->table('user_followers')
            ->join('users', 'users.id', '=', 'user_followers.user_id')
            ->where('user_followers.followed_user_id', $this->user)
            ->where('users.is_email_confirmed', true)
            ->count();

        return ['total' => $total, 'verified' => $verified];
    }

    /** @return list<array<string, mixed>> */
    private function types(Carbon $start, array $available): array
    {
        $u = $this->user;
        $out = array_fill_keys(PostType::TYPES, ['posts' => 0, 'impressions' => 0, 'engagements' => 0]);

        $posts = $this->db->table('posts')
            ->leftJoin('vantage_post_types', 'vantage_post_types.post_id', '=', 'posts.id')
            ->where('posts.user_id', $u)
            ->where('posts.type', 'comment')
            ->whereNull('posts.hidden_at')
            ->where('posts.created_at', '>=', $start->toDateTimeString())
            ->groupBy('vantage_post_types.type')
            ->selectRaw($this->wrap('vantage_post_types.type').' as t, COUNT(*) as n')
            ->get();

        foreach ($posts as $row) {
            $out[$row->t ?: 'text']['posts'] += (int) $row->n;
        }

        $impressions = $this->db->table('vantage_daily')
            ->where('user_id', $u)
            ->where('metric', Recorder::IMPRESSION)
            ->where('span', 'd')
            ->where('dim', 'like', 't:%')
            ->where('day', '>=', $start->toDateString())
            ->groupBy('dim')
            ->selectRaw('dim, SUM(value) as n')
            ->get();

        foreach ($impressions as $row) {
            $type = substr((string) $row->dim, 2);

            if (isset($out[$type])) {
                $out[$type]['impressions'] += (int) $row->n;
            }
        }

        foreach (['likes' => 'post_likes', 'reactions' => 'post_reactions', 'bookmarks' => 'post_user_bookmark'] as $source => $table) {
            if (! $available[$source]) {
                continue;
            }

            $rows = $this->db->table($table)
                ->join('posts', 'posts.id', '=', "$table.post_id")
                ->leftJoin('vantage_post_types', 'vantage_post_types.post_id', '=', 'posts.id')
                ->where('posts.user_id', $u)
                ->where("$table.user_id", '!=', $u)
                ->where("$table.created_at", '>=', $start->toDateTimeString())
                ->groupBy('vantage_post_types.type')
                ->selectRaw($this->wrap('vantage_post_types.type').' as t, COUNT(*) as n')
                ->get();

            foreach ($rows as $row) {
                $out[$row->t ?: 'text']['engagements'] += (int) $row->n;
            }
        }

        $list = [];

        foreach ($out as $type => $row) {
            $list[] = ['type' => $type] + $row;
        }

        return $list;
    }

    /**
     * The member's posts that did best in the period: most likes and
     * reactions received in it, topped up with their most-viewed posts that
     * were read in it (a post read this week counts, whenever it was written). Loaded through `whereVisibleTo` so a dashboard never shows a
     * post its viewer couldn't open.
     *
     * @return list<array<string, mixed>>
     */
    private function topPosts(Carbon $start, Carbon $now, array $available, User $viewer): array
    {
        $u = $this->user;
        $scores = [];

        foreach (['likes' => 'post_likes', 'reactions' => 'post_reactions'] as $source => $table) {
            if (! $available[$source]) {
                continue;
            }

            $rows = $this->db->table($table)
                ->join('posts', 'posts.id', '=', "$table.post_id")
                ->where('posts.user_id', $u)
                ->where("$table.user_id", '!=', $u)
                ->where("$table.created_at", '>=', $start->toDateTimeString())
                ->groupBy("$table.post_id")
                ->selectRaw($this->wrap("$table.post_id").' as id, COUNT(*) as n')
                ->orderByDesc('n')
                ->limit(10)
                ->get();

            foreach ($rows as $row) {
                $scores[(int) $row->id] = ($scores[(int) $row->id] ?? 0) + (int) $row->n;
            }
        }

        arsort($scores);
        $ids = array_slice(array_keys($scores), 0, 5);

        if (count($ids) < 5) {
            $read = $this->db->table('vantage_daily')
                ->where('user_id', $u)
                ->where('metric', Recorder::IMPRESSION)
                ->where('day', '>=', $start->toDateString())
                ->exists();

            $more = ! $read ? [] : $this->db->table('vantage_post_views')
                ->join('posts', 'posts.id', '=', 'vantage_post_views.post_id')
                ->where('posts.user_id', $u)
                ->whereNotIn('posts.id', $ids ?: [0])
                ->orderByDesc('vantage_post_views.views')
                ->limit(5 - count($ids))
                ->pluck('posts.id')
                ->all();

            $ids = array_merge($ids, array_map('intval', $more));
        }

        if (! $ids) {
            return [];
        }

        $views = $this->db->table('vantage_post_views')->whereIn('post_id', $ids)->pluck('views', 'post_id');

        $posts = Post::query()
            ->whereVisibleTo($viewer)
            ->whereIn('posts.id', $ids)
            ->with('discussion')
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($ids as $id) {
            $post = $posts->get($id);

            if (! $post || ! $post->discussion) {
                continue;
            }

            $text = trim(preg_replace('/\s+/', ' ', strip_tags(method_exists($post, 'formatContent') ? (string) $post->formatContent() : '')) ?? '');

            $out[] = [
                'id' => $id,
                'discussionId' => (int) $post->discussion_id,
                'slug' => (string) $post->discussion->slug,
                'number' => (int) $post->number,
                'title' => (string) $post->discussion->title,
                'excerpt' => mb_strlen($text) > 140 ? mb_substr($text, 0, 139).'…' : $text,
                'createdAt' => $post->created_at?->toIso8601String(),
                'views' => (int) ($views[$id] ?? 0),
                'reactions' => $scores[$id] ?? 0,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function audience(Carbon $start): array
    {
        $from = max($start->toDateString(), Carbon::now('UTC')->subDays(Rollup::DETAIL_DAYS - 1)->toDateString());

        $rows = $this->db->table('vantage_daily')
            ->where('user_id', $this->user)
            ->whereIn('metric', [Recorder::REACH, Recorder::IMPRESSION])
            ->where('span', 'd')
            ->where('day', '>=', $from)
            ->where(fn (Builder $q) => $q->where('dim', '')->orWhere('dim', 'like', 'c:%')->orWhere('dim', 'like', 'a:%')->orWhere('dim', 'like', 'g:%')->orWhere('dim', 'v:g'))
            ->groupBy('metric', 'dim')
            ->selectRaw('metric, dim, SUM(value) as n')
            ->get();

        $readers = 0;
        $impressions = 0;
        $guestImpressions = 0;
        $groups = ['c' => [], 'a' => [], 'g' => []];

        foreach ($rows as $row) {
            $n = (int) $row->n;

            if ($row->metric === Recorder::IMPRESSION) {
                $row->dim === '' ? $impressions = $n : ($row->dim === 'v:g' ? $guestImpressions = $n : null);

                continue;
            }

            if ($row->dim === '') {
                $readers = $n;
            } elseif (isset($groups[$row->dim[0]])) {
                $groups[$row->dim[0]][substr((string) $row->dim, 2)] = $n;
            }
        }

        return [
            'readers' => $readers,
            'from' => $from,
            'guestShare' => $impressions > 0 ? round($guestImpressions / $impressions * 100, 1) : null,
            'enough' => $readers >= self::AUDIENCE_MIN,
            'countries' => $this->bars($groups['c'], self::COUNTRY_MIN),
            'ages' => $readers >= self::AUDIENCE_MIN ? $this->bars($groups['a'], self::DEMOGRAPHIC_MIN) : [],
            'genders' => $readers >= self::AUDIENCE_MIN ? $this->bars($groups['g'], self::DEMOGRAPHIC_MIN) : [],
        ];
    }

    /**
     * Largest first; anything under the threshold joins "other" so a small
     * group can't be singled out. "--" (not given / unknown) stays its own.
     *
     * @param array<string, int> $values
     * @return list<array{key: string, value: int}>
     */
    private function bars(array $values, int $min): array
    {
        arsort($values);
        $out = [];
        $other = 0;

        foreach ($values as $key => $n) {
            if ($key !== '--' && $n < $min) {
                $other += $n;
            } else {
                $out[] = ['key' => (string) $key, 'value' => $n];
            }
        }

        if ($other > 0) {
            $out[] = ['key' => 'other', 'value' => $other];
        }

        return $out;
    }
}
