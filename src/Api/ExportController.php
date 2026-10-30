<?php

namespace ErnestDefoe\Vantage\Api;

use Carbon\Carbon;
use ErnestDefoe\Vantage\Access;
use ErnestDefoe\Vantage\Report\Report;
use ErnestDefoe\Vantage\Rollup;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /api/vantage/{id}/export?range=90&format=csv|json — the series, as a download. */
class ExportController implements RequestHandlerInterface
{
    public function __construct(private Report $report, private Rollup $rollup)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $query = $request->getQueryParams();
        $user = User::query()->whereVisibleTo($actor)->find((int) Arr::get($query, 'id'));

        if (! $user) {
            return new JsonResponse(['errors' => [['status' => '404']]], 404);
        }

        if (! Access::canView($actor, $user)) {
            return new JsonResponse(['errors' => [['status' => '403']]], 403);
        }

        $range = (int) Arr::get($query, 'range', 28);
        $range = in_array($range, Report::RANGES, true) ? $range : 28;

        if ($this->rollup->pending()) {
            $this->rollup->run(3);
        }

        $data = $this->report->build($user, $range, (int) Arr::get($query, 'tz', 0), $actor);
        $name = 'vantage-'.preg_replace('/[^a-z0-9_-]+/i', '-', (string) $user->username).'-'.($range ?: 'all').'-'.Carbon::now()->format('Y-m-d');

        if (Arr::get($query, 'format') === 'json') {
            return (new JsonResponse($data, 200, [], JSON_PRETTY_PRINT))
                ->withHeader('Content-Disposition', "attachment; filename=\"$name.json\"");
        }

        $stream = new Stream('php://temp', 'w+');
        $out = $stream->detach();
        fputcsv($out, array_merge(['date'], Report::SERIES, ['engagements']), ',', '"', '');

        foreach ($data['series'] as $row) {
            $engagements = array_sum(array_intersect_key($row, array_flip(Report::ENGAGEMENTS)));
            fputcsv($out, array_merge([$row['key']], array_map(fn ($k) => $row[$k], Report::SERIES), [$engagements]), ',', '"', '');
        }

        rewind($out);

        return (new Response(new Stream($out), 200))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"$name.csv\"");
    }
}
