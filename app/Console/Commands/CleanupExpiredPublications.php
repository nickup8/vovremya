<?php

namespace App\Console\Commands;

use App\Models\FreeWindowPublication;
use Illuminate\Console\Command;

class CleanupExpiredPublications extends Command
{
    protected $signature = 'publications:cleanup-expired';

    protected $description = 'Delete expired free window publications';

    public function handle(): int
    {
        $deleted = FreeWindowPublication::where('expires_at', '<', now())->delete();

        $this->info("Deleted {$deleted} expired publications.");

        return self::SUCCESS;
    }
}
