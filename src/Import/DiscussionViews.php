<?php

namespace ErnestDefoe\Vantage\Import;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;

/**
 * One-time import of FoF Discussion Views' totals, so "All time" doesn't
 * start at the day Vantage was installed.
 *
 * FoF keeps one number per discussion (`discussions.view_count`), with no
 * dates and no posts, and it counts page loads, where Vantage counts posts a
 * reader actually saw. So the totals are kept apart, as their own metric:
 * each member gets the views of the discussions they started, shown under
 * "All time" as imported, never mixed into the daily chart. Running it again
 * replaces the earlier import.
 */
class DiscussionViews
{
    public const METRIC = 'dv';

    public const SPAN = 'i';

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function available(): bool
    {
        return $this->db->getSchemaBuilder()->hasColumn('discussions', 'view_count');
    }

    /** @return array{members: int, views: int} */
    public function run(): array
    {
        if (! $this->available()) {
            return ['members' => 0, 'views' => 0];
        }

        $day = Carbon::now('UTC')->toDateString();

        // One grouped read for everyone; only members who still exist.
        $totals = $this->db->table('discussions')
            ->join('users', 'users.id', '=', 'discussions.user_id')
            ->where('discussions.view_count', '>', 0)
            ->groupBy('discussions.user_id')
            ->selectRaw($this->db->getQueryGrammar()->wrap('discussions.user_id').' as u, SUM('.$this->db->getQueryGrammar()->wrap('discussions.view_count').') as n')
            ->get();

        $this->db->transaction(function () use ($totals, $day) {
            $this->db->table('vantage_daily')->where('metric', self::METRIC)->where('span', self::SPAN)->delete();

            foreach ($totals->chunk(500) as $chunk) {
                $this->db->table('vantage_daily')->insert($chunk->map(fn ($row) => [
                    'user_id' => (int) $row->u,
                    'metric' => self::METRIC,
                    'day' => $day,
                    'span' => self::SPAN,
                    'dim' => '',
                    'value' => min((int) $row->n, 4294967295),
                ])->values()->all());
            }
        });

        return ['members' => $totals->count(), 'views' => (int) $totals->sum(fn ($row) => (int) $row->n)];
    }

    /** @return array{views: int, on: string}|null one member's imported total */
    public function of(int $userId): ?array
    {
        $row = $this->db->table('vantage_daily')
            ->where('user_id', $userId)
            ->where('metric', self::METRIC)
            ->where('span', self::SPAN)
            ->first(['day', 'value']);

        return $row ? ['views' => (int) $row->value, 'on' => substr((string) $row->day, 0, 10)] : null;
    }
}
