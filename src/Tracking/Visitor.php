<?php

namespace ErnestDefoe\Vantage\Tracking;

use Carbon\Carbon;
use ErnestDefoe\Vantage\Settings;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a request (and its actor) into a Reader, or null when they must not
 * be counted: a crawler, Global Privacy Control, or a member who opted out.
 */
class Visitor
{
    /** Anything that announces itself as a program rather than a person. */
    private const BOTS = '/bot|crawl|spider|slurp|archiver|scrape|headless|lighthouse|pagespeed|preview|monitor|uptime|curl|wget|python|httpclient|okhttp|java\/|go-http|axios|node-fetch|phantom|facebookexternalhit|embedly|whatsapp|telegram|discord|slack/i';

    public const GENDERS = ['woman', 'man', 'nonbinary'];

    public function __construct(private Country $country, private Settings $settings, private Cache $cache)
    {
    }

    public static function isBot(string $userAgent): bool
    {
        return $userAgent === '' || (bool) preg_match(self::BOTS, $userAgent);
    }

    public function for(User $actor, ?ServerRequestInterface $request): ?Reader
    {
        if ($request && $request->getHeaderLine('Sec-GPC') === '1') {
            return null;
        }

        if ($actor->isGuest()) {
            if (! $request || ! $this->settings->countGuests()) {
                return null;
            }

            $agent = $request->getHeaderLine('User-Agent');

            if (self::isBot($agent)) {
                return null;
            }

            $ip = (string) $request->getAttribute('ipAddress', '');

            return new Reader($this->hash('g|'.$ip.'|'.$agent), true, $this->country->of($request), source: $this->hash('ip|'.$ip));
        }

        if ($actor->getPreference('vantageNoCount')) {
            return null;
        }

        [$age, $gender] = $this->demographics($actor);

        return new Reader($this->hash('u|'.$actor->id), false, $this->country->of($request), $age, $gender);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function demographics(User $actor): array
    {
        if (! $this->settings->demographics() || ! $actor->getPreference('vantageShareAudience')) {
            return [null, null];
        }

        $year = (int) $actor->getPreference('vantageBirthYear');
        $age = $year > 0 ? (int) Carbon::now()->year - $year : null;

        // 🚨 A minor is never counted in a band, and neither is their gender,
        // so no breakdown can show that a minor reads someone.
        if ($age !== null && $age < 18) {
            return [null, null];
        }

        $gender = $actor->getPreference('vantageGender');

        return [$age === null ? null : self::band($age), in_array($gender, self::GENDERS, true) ? $gender : null];
    }

    public static function band(int $age): string
    {
        return match (true) {
            $age < 25 => '18-24',
            $age < 35 => '25-34',
            $age < 45 => '35-44',
            $age < 55 => '45-54',
            $age < 65 => '55-64',
            default => '65+',
        };
    }

    /**
     * A reader is a hash with a salt that changes every day and lives only in
     * the cache, so yesterday's hashes can't be recomputed, and no IP, user
     * agent or member id is ever written anywhere.
     */
    private function hash(string $value): string
    {
        $day = Carbon::now('UTC')->format('Ymd');
        $salt = $this->cache->get("vantage:salt:$day");

        if (! is_string($salt)) {
            $salt = Str::random(40);
            $this->cache->put("vantage:salt:$day", $salt, 60 * 60 * 26);
        }

        return substr(hash('sha256', $salt.'|'.$value), 0, 16);
    }
}
