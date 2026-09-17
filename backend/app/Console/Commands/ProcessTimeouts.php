<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\CallOutcome;
use Illuminate\Console\Command;

class ProcessTimeouts extends Command
{
    protected $signature = 'clients:process-timeouts';
    protected $description = 'Process voicemail timeouts and blocked client expirations';

    public function handle(): int
    {
        $this->processVoicemailTimeouts();
        $this->processExpiredReservations();
        $this->processBlockedExpirations();

        $this->info('Timeouts processed successfully.');

        return self::SUCCESS;
    }

    private function processExpiredReservations(): void
    {
        $reservations = \App\Models\Reservation::whereNull('recall_at')
            ->where('expires_at', '<=', now())
            ->with('client')
            ->get();

        foreach ($reservations as $reservation) {
            $client = $reservation->client;

            $reservation->delete();

            if ($client && $client->status === 'RESERVED') {
                $client->update(['status' => 'AVAILABLE']);
            }
        }
    }

    private function processVoicemailTimeouts(): void
    {
        $reservations = \App\Models\Reservation::whereNotNull('recall_at')
            ->where('expires_at', '<=', now())
            ->with('client')
            ->get();

        foreach ($reservations as $reservation) {
            $client = $reservation->client;
            $comercialId = $reservation->comercial_id;

            if (!$client || $client->status !== 'VOICEMAIL') {
                $reservation->delete();
                continue;
            }

            $reservation->delete();

            $client->update(['status' => 'AVAILABLE']);

            CallOutcome::create([
                'client_id' => $client->id,
                'comercial_id' => $comercialId,
                'outcome' => 'NON',
                'note' => 'Rappel boîte vocale expiré après 1 mois. Client rendu disponible.',
            ]);

            $this->checkAutoBlacklist($client);
        }
    }

    private function processBlockedExpirations(): void
    {
        Client::where('status', 'AVAILABLE')
            ->whereNotNull('blocked_until')
            ->where('blocked_until', '<=', now())
            ->update(['blocked_until' => null]);
    }

    private function checkAutoBlacklist(Client $client): void
    {
        $nonCount = CallOutcome::where('client_id', $client->id)
            ->where('outcome', 'NON')
            ->distinct('comercial_id')
            ->count();

        $activeCommercials = \App\Models\User::where('role', 'COMERCIAL')
            ->where('status', 'ACTIVE')
            ->count();

        if ($nonCount >= $activeCommercials && $activeCommercials > 0) {
            $client->update([
                'is_blacklisted' => true,
                'status' => 'BLACKLISTED',
            ]);
        }
    }
}
