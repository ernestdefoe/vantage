<?php

use Carbon\Carbon;
use Flarum\Database\Migration;

/*
 * The day counting began, so a dashboard can say "Views since Nov 4" instead
 * of letting an empty start read as a quiet month.
 */
return Migration::addSettings([
    'ernestdefoe-vantage.installed_on' => Carbon::now('UTC')->toDateString(),
]);
