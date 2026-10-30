<?php

namespace ErnestDefoe\Vantage;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Every setting in one place, cast once. Settings come back as strings, and
 * '0' is a true string, so nothing reads them raw.
 */
class Settings
{
    public const PREFIX = 'ernestdefoe-vantage.';

    public const DEFAULTS = [
        'count_guests' => true,
        'post_views' => 'everyone',
        'demographics' => false,
        'country_source' => 'auto',
        'country_header' => 'CF-IPCountry',
        'retention_months' => 13,
    ];

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    private function raw(string $key): mixed
    {
        return $this->settings->get(self::PREFIX.$key, self::DEFAULTS[$key]);
    }

    public function countGuests(): bool
    {
        return (bool) (int) $this->raw('count_guests');
    }

    /** 'everyone', 'author' or 'off'. */
    public function postViews(): string
    {
        $value = (string) $this->raw('post_views');

        return in_array($value, ['everyone', 'author', 'off'], true) ? $value : 'everyone';
    }

    public function demographics(): bool
    {
        return (bool) (int) $this->raw('demographics');
    }

    /** 'auto', 'header', 'dbip' or 'off'. */
    public function countrySource(): string
    {
        $value = (string) $this->raw('country_source');

        return in_array($value, ['auto', 'header', 'dbip', 'off'], true) ? $value : 'auto';
    }

    public function countryHeader(): string
    {
        return trim((string) $this->raw('country_header')) ?: 'CF-IPCountry';
    }

    /** Days kept day by day before they are merged into months. */
    public function retentionMonths(): int
    {
        return max(3, min(120, (int) $this->raw('retention_months')));
    }
}
