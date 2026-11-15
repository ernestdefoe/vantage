<?php

namespace ErnestDefoe\Vantage\Tracking;

use Illuminate\Cache\FileStore;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * At most LIMIT hit requests a minute from one guest address or member. A
 * real visitor sends one every few seconds at most (the browser batches);
 * anything faster is a script, and its requests are dropped before they
 * write anything. The rollup's daily caps still hold behind this.
 *
 * 🚨 Not on the file cache: it never deletes an expired file, so a key per
 * address per minute would leave a dead file behind for every visitor. The
 * daily caps in the rollup cover those forums.
 */
class HitLimiter
{
    public const LIMIT = 60;

    public function __construct(private Cache $cache)
    {
    }

    public function allow(Reader $reader): bool
    {
        if ($this->cache->getStore() instanceof FileStore) {
            return true;
        }

        $key = 'vantage:rl:'.($reader->source ?? $reader->key).':'.intdiv(time(), 60);

        $this->cache->add($key, 0, 120);

        return (int) $this->cache->increment($key) <= self::LIMIT;
    }
}
