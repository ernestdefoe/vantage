<?php

namespace ErnestDefoe\Vantage\Tracking;

use ErnestDefoe\Vantage\Settings;
use Flarum\Foundation\Paths;
use Flarum\Settings\SettingsRepositoryInterface;
use MaxMind\Db\Reader;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * A reader's country as a two-letter code, or null.
 *
 * From a trusted proxy header (Cloudflare's CF-IPCountry by default), or from
 * DB-IP's free country file on this server. The IP is looked up in memory and
 * never stored, and nothing about the reader leaves the server.
 */
class Country
{
    private ?Reader $reader = null;
    private bool $opened = false;

    public function __construct(private Settings $settings, private Paths $paths, private SettingsRepositoryInterface $settingsRepo)
    {
    }

    public function databasePath(): string
    {
        return $this->paths->storage.'/vantage/dbip-country-lite.mmdb';
    }

    /** Whether the scheduler should keep a DB-IP file on this forum. */
    public function wantsDatabase(): bool
    {
        $source = $this->settings->countrySource();

        return $source === 'dbip' || ($source === 'auto' && (bool) $this->settingsRepo->get(Settings::PREFIX.'needs_dbip'));
    }

    public function of(?ServerRequestInterface $request): ?string
    {
        $source = $this->settings->countrySource();

        if ($request === null || $source === 'off') {
            return null;
        }

        if ($source === 'auto' || $source === 'header') {
            $code = strtoupper(trim($request->getHeaderLine($this->settings->countryHeader())));

            // XX and T1 are Cloudflare's "unknown" and "Tor".
            if (preg_match('/^[A-Z]{2}$/', $code) && $code !== 'XX' && $code !== 'T1') {
                return $code;
            }

            if ($source === 'header') {
                return null;
            }
        }

        $ip = (string) $request->getAttribute('ipAddress', '');

        /*
         * "Automatic" downloads the country file only once a request has come
         * in without the header: a forum behind Cloudflare never fetches it.
         */
        if ($source === 'auto' && ! is_file($this->databasePath()) && ! $this->settingsRepo->get(Settings::PREFIX.'needs_dbip')) {
            $this->settingsRepo->set(Settings::PREFIX.'needs_dbip', '1');
        }

        if ($ip === '' || ! ($reader = $this->reader())) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (Throwable) {
            return null;
        }

        $code = is_array($record) ? ($record['country']['iso_code'] ?? null) : null;

        return is_string($code) && preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
    }

    private function reader(): ?Reader
    {
        if (! $this->opened) {
            $this->opened = true;
            $path = $this->databasePath();

            try {
                $this->reader = is_file($path) ? new Reader($path) : null;
            } catch (Throwable) {
                $this->reader = null;
            }
        }

        return $this->reader;
    }
}
