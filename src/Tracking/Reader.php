<?php

namespace ErnestDefoe\Vantage\Tracking;

/**
 * Who is reading, as far as the counts are allowed to know: hashes with a
 * one-day salt (for dedupe within the day), and the breakdowns they
 * consented to.
 */
final class Reader
{
    public function __construct(
        /** This reader today: a member or a guest's IP + browser. */
        public readonly string $key,
        public readonly bool $guest,
        public readonly ?string $country = null,
        public readonly ?string $age = null,
        public readonly ?string $gender = null,
        /** A guest's IP alone today, for the per-IP cap. */
        public readonly ?string $source = null,
    ) {
    }
}
