<?php

namespace ErnestDefoe\Vantage\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Vantage\PostType;
use ErnestDefoe\Vantage\Rollup;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;

class VantageTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 Safari/605.1.15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-likes', 'ernestdefoe-vantage');

        $posts = [];

        // Discussion 1 by member 3: posts 1–6 alternate between 3 and 4, and
        // post 7 is the reader's (2) own.
        foreach (range(1, 7) as $n) {
            $posts[] = [
                'id' => $n, 'discussion_id' => 1, 'number' => $n, 'type' => 'comment',
                'user_id' => $n === 7 ? 2 : ($n % 2 ? 3 : 4),
                'created_at' => Carbon::now()->subDays(3)->addMinutes($n),
                'content' => $n === 2 ? '<r><p>Look <IMG src="https://example.com/a.png"></IMG></p></r>' : '<t><p>Hello</p></t>',
            ];
        }

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'author', 'email' => 'author@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subYear()],
                ['id' => 4, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subYear()],
                ['id' => 5, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subYear()],
            ],
            'group_user' => [
                ['user_id' => 5, 'group_id' => Group::MODERATOR_ID],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'Open', 'created_at' => Carbon::now()->subDays(3), 'user_id' => 3, 'first_post_id' => 1, 'comment_count' => 7, 'last_post_number' => 7],
            ],
            Post::class => $posts,
        ]);
    }

    private function json(string $method, string $path, ?int $actor = null, ?array $body = null, array $query = [], array $headers = []): array
    {
        $options = $actor ? ['authenticatedAs' => $actor] : [];

        if ($body !== null) {
            $options['json'] = $body;
        }

        $request = $this->request($method, $path, $options)->withQueryParams($query);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true), (string) $response->getBody()];
    }

    private function read(int $actor, int $number): void
    {
        [$status] = $this->json('PATCH', '/api/discussions/1', $actor, ['data' => ['type' => 'discussions', 'id' => '1', 'attributes' => ['lastReadPostNumber' => $number]]]);
        $this->assertSame(200, $status);
    }

    private function rollup(): void
    {
        $this->app()->getContainer()->make(Rollup::class)->run();
    }

    /** metric|dim => value, summed over days, for one member. */
    private function totals(int $userId): array
    {
        $out = [];

        foreach ($this->database()->table('vantage_daily')->where('user_id', $userId)->get() as $row) {
            $key = $row->metric.'|'.$row->dim;
            $out[$key] = ($out[$key] ?? 0) + (int) $row->value;
        }

        return $out;
    }

    private function grantDashboard(int $groupId = Group::MEMBER_ID): void
    {
        $this->database()->table('group_permission')->insert(['group_id' => $groupId, 'permission' => 'vantage.viewDashboard']);
    }

    #[Test]
    public function a_members_read_counts_others_posts_once_and_never_their_own(): void
    {
        $this->read(2, 4);
        $this->read(2, 7);
        // Reading back up never counts twice: the read position only rises.
        $this->read(2, 3);
        $this->rollup();

        $author = $this->totals(3);
        $other = $this->totals(4);

        // Posts 1, 3, 5 are by 3; 2, 4, 6 by 4; 7 is the reader's own.
        $this->assertSame(3, $author['imp|'] ?? 0);
        $this->assertSame(3, $other['imp|'] ?? 0);
        $this->assertSame([], $this->totals(2));

        // Reached once per reader per day, however many posts were read.
        $this->assertSame(1, $author['rd|'] ?? 0);

        // Post 2 has an image.
        $this->assertSame(1, $other['imp|t:image'] ?? 0);
        $this->assertSame(1, (int) $this->database()->table('vantage_post_views')->where('post_id', 1)->value('views'));
        $this->assertSame(0, $this->database()->table('vantage_buffer')->count());
    }

    #[Test]
    public function rollups_add_to_history_instead_of_replacing_it(): void
    {
        $this->read(2, 2);
        $this->rollup();
        $this->read(2, 4);
        $this->rollup();

        $this->assertSame(2, $this->totals(3)['imp|'] ?? 0);
        $this->assertSame(2, $this->totals(4)['imp|'] ?? 0);
    }

    #[Test]
    public function a_member_who_opted_out_is_not_counted(): void
    {
        $this->app();
        $user = User::find(2);
        $user->setPreference('vantageNoCount', true);
        $user->save();

        $this->read(2, 7);
        $this->rollup();

        $this->assertSame([], $this->totals(3));
    }

    #[Test]
    public function an_opted_out_read_saved_outside_a_request_is_not_counted(): void
    {
        $this->app();
        $user = User::find(2);
        $user->setPreference('vantageNoCount', true);
        $user->save();

        // A read saved by code with no request of the reader's own.
        $state = Discussion::find(1)->stateFor($user);
        $state->read(7);
        $state->save();

        $events = $this->app()->getContainer()->make(Dispatcher::class);

        foreach ($state->releaseEvents() as $event) {
            $events->dispatch($event);
        }

        $this->rollup();

        $this->assertSame([], $this->totals(3));
    }

    #[Test]
    public function the_install_day_is_remembered(): void
    {
        $this->app();

        $this->assertSame(Carbon::now('UTC')->toDateString(), $this->database()->table('settings')->where('key', 'ernestdefoe-vantage.installed_on')->value('value'));
    }

    #[Test]
    public function global_privacy_control_is_respected(): void
    {
        $this->json('POST', '/api/vantage/hit', null, ['type' => 'seen', 'discussion' => 1, 'numbers' => [1, 2]], [], ['User-Agent' => self::BROWSER, 'Sec-GPC' => '1']);
        $this->rollup();

        $this->assertSame([], $this->totals(3));
    }

    #[Test]
    public function guests_are_counted_from_the_browser_once_and_crawlers_never(): void
    {
        [$status] = $this->json('POST', '/api/vantage/hit', null, ['type' => 'seen', 'discussion' => 1, 'numbers' => [1, 2, 3]], [], ['User-Agent' => self::BROWSER]);
        $this->assertSame(204, $status);

        // The same guest sending the same posts again (another tab, a retry).
        $this->json('POST', '/api/vantage/hit', null, ['type' => 'seen', 'discussion' => 1, 'numbers' => [1, 2, 3]], [], ['User-Agent' => self::BROWSER]);

        $this->json('POST', '/api/vantage/hit', null, ['type' => 'seen', 'discussion' => 1, 'numbers' => [1, 2, 3]], [], ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']);
        $this->json('POST', '/api/vantage/hit', null, ['type' => 'seen', 'discussion' => 1, 'numbers' => [5]], [], []);
        $this->rollup();

        $author = $this->totals(3);
        $this->assertSame(2, $author['imp|'] ?? 0);
        $this->assertSame(2, $author['imp|v:g'] ?? 0);
        $this->assertSame(1, $this->totals(4)['imp|'] ?? 0);
    }

    #[Test]
    public function guest_counting_can_be_switched_off(): void
    {
        $this->setting('ernestdefoe-vantage.count_guests', '0');

        $this->json('POST', '/api/vantage/hit', null, ['type' => 'seen', 'discussion' => 1, 'numbers' => [1]], [], ['User-Agent' => self::BROWSER]);
        $this->rollup();

        $this->assertSame([], $this->totals(3));
    }

    #[Test]
    public function members_hits_cannot_add_reads_from_the_browser(): void
    {
        // Members are counted by the read tracker only; a crafted "seen" is ignored.
        $this->json('POST', '/api/vantage/hit', 2, ['type' => 'seen', 'discussion' => 1, 'numbers' => [1, 3, 5]]);
        $this->rollup();

        $this->assertSame([], $this->totals(3));
    }

    #[Test]
    public function profile_visits_count_once_a_day_and_not_your_own(): void
    {
        $this->json('GET', '/api/users/3', 2);
        $this->json('GET', '/api/users/3', 2);
        $this->json('GET', '/api/users/3', 3);
        $this->json('GET', '/api/users/3', 4);
        $this->rollup();

        $this->assertSame(2, $this->totals(3)['pv|'] ?? 0);
    }

    #[Test]
    public function a_profile_loaded_as_an_image_is_not_a_visit(): void
    {
        // ![](/api/users/3) in a post must not count everyone who reads it.
        $this->json('GET', '/api/users/3', 2, null, [], ['Sec-Fetch-Dest' => 'image']);
        $this->json('GET', '/api/users/3', 4, null, [], ['Sec-Fetch-Dest' => 'empty']);
        $this->rollup();

        $this->assertSame(1, $this->totals(3)['pv|'] ?? 0);
    }

    #[Test]
    public function the_same_post_counts_once_per_reader_a_day_across_rollups(): void
    {
        $hit = fn (string $agent) => $this->json('POST', '/api/vantage/hit', null, ['type' => 'seen', 'discussion' => 1, 'numbers' => [1]], [], ['User-Agent' => $agent]);

        $hit(self::BROWSER);
        $this->rollup();
        $hit(self::BROWSER);
        $this->rollup();
        // Another browser on the same connection is another reader.
        $hit(self::BROWSER.' Firefox/140.0');
        $this->rollup();

        $this->assertSame(2, $this->totals(3)['imp|'] ?? 0);
        $this->assertSame(2, $this->totals(3)['rd|'] ?? 0);
    }

    #[Test]
    public function a_share_click_credits_the_posts_author(): void
    {
        $this->json('POST', '/api/vantage/hit', 2, ['type' => 'share', 'post' => 1]);
        $this->json('POST', '/api/vantage/hit', 2, ['type' => 'share', 'post' => 1]);
        // Sharing your own post isn't an engagement.
        $this->json('POST', '/api/vantage/hit', 3, ['type' => 'share', 'post' => 1]);
        $this->rollup();

        $this->assertSame(1, $this->totals(3)['share|'] ?? 0);
    }

    #[Test]
    public function views_under_posts_follow_the_setting(): void
    {
        $this->read(2, 7);
        $this->rollup();

        [, $body] = $this->json('GET', '/api/posts', null, [], ['filter' => ['discussion' => 1]]);
        $views = collect($body['data'])->mapWithKeys(fn ($p) => [$p['id'] => $p['attributes']['vantageViews'] ?? 'missing']);
        $this->assertSame(1, $views['1']);
        $this->assertSame(0, $views['7']);
    }

    #[Test]
    public function views_can_be_shown_to_the_author_only(): void
    {
        $this->setting('ernestdefoe-vantage.post_views', 'author');
        $this->read(2, 7);
        $this->rollup();

        [, $body] = $this->json('GET', '/api/posts', 3, [], ['filter' => ['discussion' => 1]]);
        $views = collect($body['data'])->mapWithKeys(fn ($p) => [$p['id'] => $p['attributes']['vantageViews'] ?? 'missing']);
        $this->assertSame(1, $views['1']);
        $this->assertSame('missing', $views['2']);
    }

    #[Test]
    public function the_views_field_costs_one_query_however_many_posts(): void
    {
        $count = function (): int {
            $db = $this->database();
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->json('GET', '/api/posts', 1, [], ['filter' => ['discussion' => 1]]);
            $log = $db->getQueryLog();
            $db->disableQueryLog();

            return count(array_filter($log, fn ($q) => str_contains($q['query'], 'vantage_post_views')));
        };

        $this->assertSame(1, $count());
    }

    #[Test]
    public function the_dashboard_needs_the_permission_and_says_who_has_it(): void
    {
        [$status, $body] = $this->json('GET', '/api/vantage/3', 3);
        $this->assertSame(403, $status);
        $this->assertSame('vantage_locked', $body['errors'][0]['code']);
        $this->assertSame([], $body['groups']);

        // Sold or given to one group: everyone else sees who has it.
        $this->grantDashboard(Group::MODERATOR_ID);

        [$status, $body] = $this->json('GET', '/api/vantage/3', 3);
        $this->assertSame(403, $status);
        $this->assertSame(['Mods'], $body['groups']);

        // Someone else's locked dashboard says nothing about who has it.
        [, $body] = $this->json('GET', '/api/vantage/4', 3);
        $this->assertSame([], $body['groups'] ?? []);
    }

    #[Test]
    public function a_member_with_the_permission_sees_their_numbers(): void
    {
        $this->grantDashboard(Group::MEMBER_ID);
        $this->database()->table('post_likes')->insert([
            ['post_id' => 1, 'user_id' => 4, 'created_at' => Carbon::now()->subHour()],
            ['post_id' => 1, 'user_id' => 3, 'created_at' => Carbon::now()->subHour()],
        ]);

        $this->read(2, 7);

        [$status, $body] = $this->json('GET', '/api/vantage/3', 3, null, ['range' => 7, 'tz' => -300]);
        $this->assertSame(200, $status);

        $this->assertSame(3, $body['totals']['impressions']);
        // A like on your own post isn't an engagement.
        $this->assertSame(1, $body['totals']['likes']);
        // Replies in the discussion 3 started, by others: posts 2, 4, 6 (by 4) and 7 (by 2).
        $this->assertSame(4, $body['totals']['replies']);
        $this->assertSame(5, $body['tiles']['engagements'][0]);
        $this->assertCount(8, $body['series']);
        $this->assertSame(1, $body['topPosts'][0]['id']);
        $this->assertGreaterThan(0, array_sum(array_map('array_sum', $body['bestTimes'])));
    }

    #[Test]
    public function moderators_can_open_any_dashboard_and_members_cannot(): void
    {
        $this->grantDashboard(Group::MODERATOR_ID);

        [$status] = $this->json('GET', '/api/vantage/3', 4);
        $this->assertSame(403, $status);

        [$status] = $this->json('GET', '/api/vantage/3', 5);
        $this->assertSame(200, $status);

        [$status] = $this->json('GET', '/api/vantage/3');
        $this->assertSame(403, $status);

        [$status] = $this->json('GET', '/api/vantage/999', 5);
        $this->assertSame(404, $status);
    }

    #[Test]
    public function export_is_a_csv_of_the_series(): void
    {
        $this->read(2, 7);

        [$status, , $raw] = $this->json('GET', '/api/vantage/3/export', 1, null, ['range' => 7]);
        $this->assertSame(200, $status);

        $lines = array_values(array_filter(explode("\n", $raw)));
        $this->assertStringStartsWith('date,impressions,replies,likes', $lines[0]);
        $this->assertCount(9, $lines);
    }

    #[Test]
    public function post_types_are_read_from_the_stored_xml(): void
    {
        $this->assertSame('image', PostType::of('<r><p><IMG src="x"></IMG></p></r>'));
        $this->assertSame('video', PostType::of('<r><YOUTUBE id="abc" url="x">x</YOUTUBE><IMG src="y"></IMG></r>'));
        $this->assertSame('text', PostType::of('<t><p>Imagine</p></t>'));
    }

    #[Test]
    public function old_days_merge_into_months_without_losing_counts(): void
    {
        $db = $this->database();
        $old = Carbon::now('UTC')->subMonths(20)->startOfMonth();

        foreach (range(0, 9) as $i) {
            $db->table('vantage_daily')->insert(['user_id' => 3, 'metric' => 'imp', 'day' => $old->copy()->addDays($i)->toDateString(), 'span' => 'd', 'dim' => '', 'value' => 2]);
        }

        $this->app()->getContainer()->make(Rollup::class)->pruneDaily(true);

        $this->assertSame(0, $db->table('vantage_daily')->where('span', 'd')->where('user_id', 3)->count());
        $this->assertSame(20, (int) $db->table('vantage_daily')->where('span', 'm')->where('user_id', 3)->sum('value'));
    }

    #[Test]
    public function a_minors_age_and_gender_are_never_counted(): void
    {
        $this->setting('ernestdefoe-vantage.demographics', '1');

        $this->app();
        $user = User::find(2);
        $user->setPreference('vantageShareAudience', true);
        $user->setPreference('vantageBirthYear', (int) date('Y') - 15);
        $user->setPreference('vantageGender', 'woman');
        $user->save();

        $this->read(2, 1);
        $this->rollup();

        $totals = $this->totals(3);
        $this->assertSame(1, $totals['rd|a:--'] ?? 0);
        $this->assertSame(1, $totals['rd|g:--'] ?? 0);
    }

    #[Test]
    public function an_adult_who_opted_in_is_counted_in_a_band(): void
    {
        $this->setting('ernestdefoe-vantage.demographics', '1');

        $this->app();
        $user = User::find(2);
        $user->setPreference('vantageShareAudience', true);
        $user->setPreference('vantageBirthYear', (int) date('Y') - 30);
        $user->setPreference('vantageGender', 'woman');
        $user->save();

        $this->read(2, 1);
        $this->rollup();

        $totals = $this->totals(3);
        $this->assertSame(1, $totals['rd|a:25-34'] ?? 0);
        $this->assertSame(1, $totals['rd|g:woman'] ?? 0);
    }

    #[Test]
    public function country_comes_from_the_trusted_header(): void
    {
        $this->json('POST', '/api/vantage/hit', null, ['type' => 'seen', 'discussion' => 1, 'numbers' => [1]], [], ['User-Agent' => self::BROWSER, 'CF-IPCountry' => 'NL']);
        $this->rollup();

        $this->assertSame(1, $this->totals(3)['rd|c:NL'] ?? 0);
    }

    #[Test]
    public function gdpr_exports_and_erases_only_the_members_own_statistics(): void
    {
        $this->read(2, 7);
        $this->rollup();

        $user = User::find(3);
        $data = $this->app()->getContainer()->make(\ErnestDefoe\Vantage\Gdpr\VantageData::class, ['user' => $user, 'erasureRequest' => null]);

        $export = $data->export();
        $this->assertStringContainsString('"metric": "imp"', $export[0]['vantage/statistics.json']);
        $this->assertStringContainsString('"views": 1', $export[1]['vantage/post-views.json']);

        $data->delete();

        $this->assertSame([], $this->totals(3));
        $this->assertNotSame([], $this->totals(4));
        $this->assertSame(0, $this->database()->table('vantage_post_views')->whereIn('post_id', [1, 3, 5])->count());
    }
}
