<?php

namespace ErnestDefoe\Vantage\Console;

use ErnestDefoe\Vantage\Import\DiscussionViews;
use Illuminate\Console\Command;

class ImportDiscussionViewsCommand extends Command
{
    protected $signature = 'vantage:import-discussion-views';

    protected $description = "Import FoF Discussion Views' totals into each member's all-time Vantage stats (replaces an earlier import).";

    public function handle(DiscussionViews $import): int
    {
        if (! $import->available()) {
            $this->error('FoF Discussion Views has never been installed here: there is nothing to import.');

            return 1;
        }

        $result = $import->run();
        $this->info("Imported {$result['views']} views for {$result['members']} members.");

        return 0;
    }
}
