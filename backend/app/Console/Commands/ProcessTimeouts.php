<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\CallWorkflowService;
use Illuminate\Console\Command;

class ProcessTimeouts extends Command
{
    protected $signature = 'clients:process-timeouts';
    protected $description = 'Process expired recalls (BV / Injoignable) without deleting reservations';

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
     * Rappel expiré sans action -> échec automatique (compteur++,
     * >= 2 -> UNAVAILABLE_TEMP 21 jours). La réservation est conservée,
     * recall_at est vidé : le client réapparaît dans les listes.
     */
    private function processExpiredRecalls(): void
    {
        $reservations = Reservation::whereNotNull('recall_at')
            ->where('recall_at', '<=', now())
            ->with('client')
            ->get();

        foreach ($reservations as $reservation) {
            $blocked = $this->workflow->handleRecallExpired($reservation);

            if ($blocked) {
                $this->line("Client {$reservation->client_id} : tentatives épuisées -> UNAVAILABLE_TEMP 21 jours.");
            }
        }

        $this->info($reservations->count().' rappel(s) expiré(s) traité(s).');
    }
}
