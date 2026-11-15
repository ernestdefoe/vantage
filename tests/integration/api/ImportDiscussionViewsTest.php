<?php

namespace ErnestDefoe\Vantage\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Group\Group;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

class ImportDiscussionViewsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-discussion-views', 'ernestdefoe-vantage');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'author', 'email' => 'author@machine.local', 'is_email_confirmed' => 1, 'joined_at' => Carbon::now()->subYear()],
            ],
            Discussion::class => [
                ['id' => 1, 'title' => 'One', 'created_at' => Carbon::now()->subYear(), 'user_id' => 3, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1, 'view_count' => 1000],
                ['id' => 2, 'title' => 'Two', 'created_at' => Carbon::now()->subYear(), 'user_id' => 3, 'first_post_id' => 2, 'comment_count' => 1, 'last_post_number' => 1, 'view_count' => 234],
                ['id' => 3, 'title' => 'Gone author', 'created_at' => Carbon::now()->subYear(), 'user_id' => null, 'first_post_id' => 3, 'comment_count' => 1, 'last_post_number' => 1, 'view_count' => 50],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'type' => 'comment', 'user_id' => 3, 'created_at' => Carbon::now()->subYear(), 'content' => '<t><p>One</p></t>'],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'type' => 'comment', 'user_id' => 3, 'created_at' => Carbon::now()->subYear(), 'content' => '<t><p>Two</p></t>'],
                ['id' => 3, 'discussion_id' => 3, 'number' => 1, 'type' => 'comment', 'user_id' => null, 'created_at' => Carbon::now()->subYear(), 'content' => '<t><p>Three</p></t>'],
            ],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'vantage.viewDashboard'],
            ],
        ]);
    }

    private function json(string $method, string $path, int $actor, array $query = []): array
    {
        $response = $this->send($this->request($method, $path, ['authenticatedAs' => $actor])->withQueryParams($query));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function only_admins_can_import(): void
    {
        [$status] = $this->json('POST', '/api/vantage/import-discussion-views', 3);

        $this->assertSame(403, $status);
    }

    #[Test]
    public function totals_import_as_their_own_all_time_number_and_a_rerun_replaces_them(): void
    {
        [$status, $result] = $this->json('POST', '/api/vantage/import-discussion-views', 1);
        $this->assertSame(200, $status);
        $this->assertSame(['members' => 1, 'views' => 1234], $result);

        $this->database()->table('discussions')->where('id', 2)->update(['view_count' => 300]);
        [, $result] = $this->json('POST', '/api/vantage/import-discussion-views', 1);
        $this->assertSame(1300, $result['views']);

        [, $all] = $this->json('GET', '/api/vantage/3', 3, ['range' => 0]);
        $this->assertSame(1300, $all['imported']['views']);
        // Kept apart from Vantage's own views: page loads, not posts seen.
        $this->assertSame(0, $all['tiles']['impressions'][0]);

        [, $month] = $this->json('GET', '/api/vantage/3', 3, ['range' => 28]);
        $this->assertNull($month['imported']);
    }
}
