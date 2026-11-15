<?php

namespace ErnestDefoe\Vantage\Api;

use ErnestDefoe\Vantage\Import\DiscussionViews;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /api/vantage/import-discussion-views — admins only, CSRF-checked. */
class ImportController implements RequestHandlerInterface
{
    public function __construct(private DiscussionViews $import)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        if (! $this->import->available()) {
            return new JsonResponse(['errors' => [['status' => '422', 'code' => 'vantage_no_discussion_views']]], 422);
        }

        $result = $this->import->run();

        return new JsonResponse($result);
    }
}
