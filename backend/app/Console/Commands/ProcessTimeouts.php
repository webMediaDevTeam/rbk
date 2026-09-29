<?php

namespace App\Console\Commands;

use App\Models\Rappel;
use App\Services\CallWorkflowService;
use Illuminate\Console\Command;

class ProcessTimeouts extends Command
{
    protected $signature = 'clients:process-timeouts';
    protected $description = 'Process expired recalls (BV / CALL_BACK) without deleting reservations';

    public function __construct(private CallWorkflowService $workflow)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->processExpiredRecalls();

        $this->info('Timeouts processed successfully.');

        return self::SUCCESS;
    }

    /**
     * Rappel échu sans action -> échec automatique (compteur++,
     * >= 2 -> UNAVAILABLE 21 jours). La réservation est conservée, le
     * rappel est retiré : le client réapparaît dans les listes.
     *
     * Source : table `rappels` (les colonnes de rappel de `reservations`
     * ont été supprimées).
     */
    private function processExpiredRecalls(): void
    {
        $rappels = Rappel::where('reminder_date', '<=', now())
            ->with('client')
            ->get();

        foreach ($rappels as $rappel) {
            $blocked = $this->workflow->handleRecallExpired($rappel);

            if ($blocked) {
                $this->line("Client {$rappel->client_id} : tentatives épuisées -> UNAVAILABLE 21 jours.");
            }
        }

        $this->info($rappels->count().' rappel(s) expiré(s) traité(s).');
    }
}
