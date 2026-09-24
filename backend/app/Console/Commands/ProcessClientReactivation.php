<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;

class ProcessClientReactivation extends Command
{
    protected $signature = 'clients:reactivate';
    protected $description = 'Reactivate UNAVAILABLE_TEMP clients whose returned_at has passed (back to AVAILABLE for everyone)';

    public function handle(): int
    {
        $count = Client::where('status', 'UNAVAILABLE_TEMP')
            ->whereNotNull('returned_at')
            ->where('returned_at', '<=', now())
            ->update([
                'status' => 'AVAILABLE',
                'returned_at' => null,
            ]);

        $this->info($count.' client(s) réactivé(s) -> AVAILABLE.');

        return self::SUCCESS;
    }
}
