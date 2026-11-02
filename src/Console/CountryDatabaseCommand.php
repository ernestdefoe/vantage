<?php

namespace ErnestDefoe\Vantage\Console;

use Carbon\Carbon;
use ErnestDefoe\Vantage\Settings;
use ErnestDefoe\Vantage\Tracking\Country;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Throwable;

/**
 * Keeps DB-IP's free country file (CC BY 4.0) in storage/vantage, refreshed
 * monthly. Only the file is downloaded; no visitor data is ever sent.
 *
 * Plain file functions on purpose: the MaxMind reader opens a local path, so a
 * Filesystem disk (possibly S3) would only add a copy step.
 */
class CountryDatabaseCommand extends Command
{
    protected $signature = 'vantage:country-database {--force : Download even if the file is fresh or not needed}';

    protected $description = 'Download or refresh the DB-IP country database Vantage uses for audience countries.';

    public function handle(Country $country, SettingsRepositoryInterface $settings): int
    {
        $path = $country->databasePath();
        $fresh = is_file($path) && filemtime($path) > time() - 32 * 86400;

        if (! $this->option('force') && ($fresh || ! $country->wantsDatabase())) {
            return 0;
        }

        // A failure is remembered for a day, so a host that blocks outgoing
        // HTTPS isn't retried every run.
        $failed = (string) $settings->get(Settings::PREFIX.'dbip_failed_on');

        if (! $this->option('force') && $failed === Carbon::now('UTC')->toDateString()) {
            return 0;
        }

        @mkdir(dirname($path), 0775, true);
        $client = new Client(['timeout' => 120, 'connect_timeout' => 10]);

        foreach ([Carbon::now('UTC'), Carbon::now('UTC')->subMonthNoOverflow()] as $month) {
            $url = 'https://download.db-ip.com/free/dbip-country-lite-'.$month->format('Y-m').'.mmdb.gz';
            $tmp = $path.'.download';

            try {
                $client->get($url, ['sink' => $tmp]);
                $data = gzdecode((string) file_get_contents($tmp));
                @unlink($tmp);

                if (! $data || strlen($data) < 100000) {
                    continue;
                }

                file_put_contents($path.'.new', $data);
                rename($path.'.new', $path);
                $settings->delete(Settings::PREFIX.'dbip_failed_on');
                $settings->set(Settings::PREFIX.'dbip_error', '');
                $this->info("Saved $url");

                return 0;
            } catch (Throwable $e) {
                @unlink($tmp);
                $error = $e->getMessage();
            }
        }

        $settings->set(Settings::PREFIX.'dbip_failed_on', Carbon::now('UTC')->toDateString());
        $settings->set(Settings::PREFIX.'dbip_error', mb_substr($error ?? 'The download was empty.', 0, 300));
        $this->error('Could not download the country database: '.($error ?? 'empty file'));

        return 1;
    }
}
