<?php

namespace ErnestDefoe\Vantage\Tracking;

use Flarum\Api\Context;
use Flarum\User\User;

/**
 * After-hook on the user "show" endpoint: a member opening someone else's
 * profile. The profile page asks for the user once per visit (or the forum
 * asks on the member's behalf on a direct load), so this rides on a request
 * that happens anyway. Guests are counted by the browser instead, which keeps
 * crawlers out.
 */
class CountProfileVisit
{
    public function __invoke(Context $context, mixed $user): mixed
    {
        $actor = $context->getActor();

        /*
         * 🚨 Not when the browser is loading the URL as an image (or a frame,
         * a script…): a post with ![](/api/users/5) in it would otherwise
         * count a visit from everyone who reads that post. The forum's own
         * requests are "empty" (fetch) or "document" (a direct page load).
         */
        $dest = $context->request->getHeaderLine('Sec-Fetch-Dest');

        if ($dest !== '' && $dest !== 'empty' && $dest !== 'document') {
            return $user;
        }

        if ($user instanceof User && ! $actor->isGuest() && $actor->id !== $user->id) {
            $reader = resolve(Visitor::class)->for($actor, $context->request);

            if ($reader) {
                resolve(Recorder::class)->profileVisit($reader, (int) $user->id);
            }
        }

        return $user;
    }
}
