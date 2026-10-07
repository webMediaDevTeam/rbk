<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Enterprise;
use App\Models\Note;
use App\Models\Reservation;
use App\Models\ReservationGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * GET /dashboard/stats
     * Role-aware dashboard: admins get global figures, commercials get their own.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        if (in_array($user->role, ['ADMIN', 'SUPER_ADMIN'], true)) {
            return $this->adminStats($user);
        }

        return $this->commercialStats($user);
    }

    private function adminStats(User $user): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'role' => $user->role,
                'stats' => [
                    'enterprises' => Enterprise::count(),
                    'commercials' => User::where('role', 'COMERCIAL')->count(),
                    'prospects' => Client::count(),
                    'groups' => ReservationGroup::count(),
                    'reservations' => Reservation::count(),
                    'calls' => Note::calls()->count(),
                    'clients_called' => Note::calls()->distinct()->count('client_id'),
                    'clients_oui' => Note::where('type', Note::TYPE_YES)->distinct()->count('client_id'),
                    'blacklisted' => Client::where('is_blacklisted', true)->count(),
                ],
                'calls_by_day' => $this->callsByDay(),
                'top_commercials' => $this->topCommercials(),
            ],
        ]);
    }

    private function commercialStats(User $user): JsonResponse
    {
        $id = $user->id;

        return response()->json([
            'success' => true,
            'data' => [
                'role' => $user->role,
                'stats' => [
                    'groups' => ReservationGroup::where('comercial_id', $id)->count(),
                    'reservations' => Reservation::where('comercial_id', $id)->count(),
                    'calls' => Note::where('sender_id', $id)->calls()->count(),
                    'clients_called' => Note::where('sender_id', $id)->calls()->distinct()->count('client_id'),
                    'clients_oui' => Note::where('sender_id', $id)->where('type', Note::TYPE_YES)->distinct()->count('client_id'),
                ],
                'calls_by_day' => $this->callsByDay($id),
                'top_commercials' => [],
            ],
        ]);
    }

    /**
     * Calls per day over the last 14 days (optionally scoped to one commercial).
     */
    private function callsByDay(?string $comercialId = null): array
    {
        $from = Carbon::today()->subDays(13)->startOfDay();

        $query = Note::calls()
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN type = 'YES' THEN 1 ELSE 0 END) as oui")
            ->groupBy('day')
            ->orderBy('day');

        if ($comercialId) {
            $query->where('sender_id', $comercialId);
        }

        $rows = $query->get()->keyBy('day');

        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $key = $date->toDateString();
            $row = $rows->get($key);

            $days[] = [
                'date' => $key,
                'label' => $date->format('d/m'),
                'total' => (int) ($row->total ?? 0),
                'oui' => (int) ($row->oui ?? 0),
            ];
        }

        return $days;
    }

    /**
     * Best performing commercials (by successful clients), for the admin dashboard.
     */
    private function topCommercials(): array
    {
        return User::where('role', 'COMERCIAL')->with('employee')->get()
            ->map(function (User $u) {
                $clientsCalled = Note::where('sender_id', $u->id)->calls()->distinct()->count('client_id');
                $clientsOui = Note::where('sender_id', $u->id)->where('type', Note::TYPE_YES)->distinct()->count('client_id');

                return [
                    'id' => $u->id,
                    'name' => trim(($u->employee?->first_name ?? $u->first_name ?? '').' '.($u->employee?->last_name ?? $u->last_name ?? '')) ?: $u->email,
                    'email' => $u->email,
                    'calls' => Note::where('sender_id', $u->id)->calls()->count(),
                    'reservations' => Reservation::where('comercial_id', $u->id)->count(),
                    'clients_called' => $clientsCalled,
                    'clients_oui' => $clientsOui,
                ];
            })
            ->sortByDesc('clients_oui')
            ->values()
            ->take(5)
            ->all();
    }
}
