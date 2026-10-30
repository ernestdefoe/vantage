<?php

namespace ErnestDefoe\Vantage\Tracking;

/**
 * Who is reading, as far as the counts are allowed to know: a key that only
 * lives in the cache for a day, and the breakdowns they consented to.
 */
final class Reader
{
    public function __construct(
        public readonly string $key,
        public readonly bool $guest,
        public readonly ?string $country = null,
        public readonly ?string $age = null,
        public readonly ?string $gender = null,
    ) {
    }
}
