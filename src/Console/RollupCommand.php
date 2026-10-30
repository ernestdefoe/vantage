<?php

namespace ErnestDefoe\Vantage\Console;

use ErnestDefoe\Vantage\Rollup;
use Illuminate\Console\Command;

class RollupCommand extends Command
{
    protected $signature = 'vantage:rollup {--prune : Also merge and prune old rows now}';

    protected $description = 'Fold counted views, visits and shares into Vantage history.';

    public function handle(Rollup $rollup): int
    {
        $n = $rollup->run();

        if ($this->option('prune')) {
            $rollup->pruneDaily(true);
        }

        $this->info("Folded in $n events.");

        return 0;
    }
}
