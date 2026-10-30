<?php

namespace ErnestDefoe\Vantage\Tracking;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CaptureRequest implements MiddlewareInterface
{
    public function __construct(private RequestHolder $holder)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $previous = $this->holder->request;
        $this->holder->request = $request;

        try {
            return $handler->handle($request);
        } finally {
            // Internal API calls nest; the outer request is current again after.
            $this->holder->request = $previous;
        }
    }
}
