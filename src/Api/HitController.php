<?php

namespace ErnestDefoe\Vantage\Api;

use ErnestDefoe\Vantage\Tracking\Recorder;
use ErnestDefoe\Vantage\Tracking\Visitor;
use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/vantage/hit — what only the browser can tell us.
 *
 *   seen     a guest's posts that were on screen (members are counted by the
 *            read tracker, without this request)
 *   profile  a guest opened a profile (members: the user endpoint itself)
 *   share    anyone clicked a share button
 *
 * Always 204, whatever happened: the answer never says whether something
 * was counted, exists or is visible.
 */
class HitController implements RequestHandlerInterface
{
    private const MAX_NUMBERS = 60;

    public function __construct(private Recorder $recorder, private Visitor $visitor)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $body = (array) $request->getParsedBody();
        $type = (string) Arr::get($body, 'type');
        $reader = $this->visitor->for($actor, $request);

        if ($reader) {
            match ($type) {
                'seen' => $actor->isGuest() && $this->seen($actor, $reader, $body),
                'profile' => $actor->isGuest() && $this->profile($actor, $reader, (int) Arr::get($body, 'user')),
                'share' => $this->share($actor, $reader, (int) Arr::get($body, 'post')),
                default => null,
            };
        }

        return new EmptyResponse(204);
    }

    /** @param array<string, mixed> $body */
    private function seen(User $actor, \ErnestDefoe\Vantage\Tracking\Reader $reader, array $body): bool
    {
        $discussion = (int) Arr::get($body, 'discussion');
        $numbers = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) Arr::get($body, 'numbers', []))))), 0, self::MAX_NUMBERS);

        if (! $discussion || ! $numbers) {
            return false;
        }

        // Only posts a guest could see: a crafted request can't count others.
        $authors = Post::query()
            ->whereVisibleTo($actor)
            ->where('posts.discussion_id', $discussion)
            ->whereIn('posts.number', $numbers)
            ->where('posts.type', 'comment')
            ->whereNotNull('posts.user_id')
            ->pluck('posts.user_id', 'posts.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->recorder->impressions($reader, $authors) > 0;
    }

    private function profile(User $actor, \ErnestDefoe\Vantage\Tracking\Reader $reader, int $userId): bool
    {
        $user = $userId ? User::query()->whereVisibleTo($actor)->find($userId) : null;

        if (! $user) {
            return false;
        }

        $this->recorder->profileVisit($reader, (int) $user->id);

        return true;
    }

    private function share(User $actor, \ErnestDefoe\Vantage\Tracking\Reader $reader, int $postId): bool
    {
        $post = $postId ? Post::query()->whereVisibleTo($actor)->where('type', 'comment')->find($postId) : null;

        if (! $post || ! $post->user_id || (int) $post->user_id === $actor->id) {
            return false;
        }

        $this->recorder->share($reader, (int) $post->user_id, (int) $post->id);

        return true;
    }
}
