<?php

namespace ErnestDefoe\Vantage\Tracking;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The API request being handled, for listeners that only get a model.
 *
 * `UserRead` carries the discussion state and nothing else, yet the country
 * comes from the request's headers or IP, and Global Privacy Control is a
 * header. Holding the request is one assignment per API request.
 */
class RequestHolder
{
    public ?ServerRequestInterface $request = null;
}
