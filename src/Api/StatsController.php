<?php

namespace ErnestDefoe\Vantage\Api;

use ErnestDefoe\Vantage\Access;
use ErnestDefoe\Vantage\Report\Report;
use ErnestDefoe\Vantage\Rollup;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/vantage/{id}?range=28&tz=-300 — one member's dashboard.
 *
 * Folds any waiting events in first (under the rollup's lock), so the
 * numbers are current even on a forum whose scheduler never runs.
 */
class StatsController implements RequestHandlerInterface
{
    public function __construct(private Report $report, private Rollup $rollup, private Access $access, private Cache $cache)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $query = $request->getQueryParams();
        $user = User::query()->whereVisibleTo($actor)->find((int) Arr::get($query, 'id'));

        // 🚨 404 for a member the actor may not see: a 403 would confirm they exist.
        if (! $user) {
            return new JsonResponse(['errors' => [['status' => '404']]], 404);
        }

        if (! Access::canView($actor, $user)) {
            return new JsonResponse([
                'errors' => [['status' => '403', 'code' => 'vantage_locked']],
                // Your own Stats tab shows what you'd get and who gets it.
                'groups' => $actor->id === $user->id ? $this->access->groups() : [],
            ], 403);
        }

        $range = (int) Arr::get($query, 'range', 28);
        $range = in_array($range, Report::RANGES, true) ? $range : 28;
        $tz = (int) Arr::get($query, 'tz', 0);

        if ($this->rollup->pending()) {
            $this->rollup->run(3);
        }

        $data = $this->cache->remember(
            "vantage:report:{$user->id}:$range:$tz:".($actor->id === $user->id ? 'own' : $actor->id),
            120,
            fn () => $this->report->build($user, $range, $tz, $actor)
        );

        return new JsonResponse($data);
    }
}
