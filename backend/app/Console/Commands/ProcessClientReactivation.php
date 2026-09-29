<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Note;
use Illuminate\Console\Command;

class ProcessClientReactivation extends Command
{
    protected $signature = 'clients:reactivate';
    protected $description = 'Reactivate UNAVAILABLE clients whose returned_at has passed (back to AVAILABLE for everyone)';

    public function handle(): int
    {
        $count = 0;

        Client::where('status', Client::STATUS_UNAVAILABLE)
            ->whereNotNull('returned_at')
            ->where('returned_at', '<=', now())
            ->chunkById(200, function ($clients) use (&$count) {
                foreach ($clients as $client) {
                    $client->update([
                        'status' => Client::STATUS_AVAILABLE,
                        'returned_at' => null,
                    ]);

                    // Evénement du journal : le retour est historisé (émetteur
                    // SYSTEM, aucun compte utilisateur).
                    Note::create([
                        'client_id' => $client->id,
                        'sender_id' => Note::SENDER_SYSTEM,
                        'type' => Note::TYPE_RETURNED_TO_AVAILABLE,
                    ]);

                    $count++;
                }
            });

        $this->info($count.' client(s) réactivé(s) -> AVAILABLE.');

        return self::SUCCESS;
    }
}
