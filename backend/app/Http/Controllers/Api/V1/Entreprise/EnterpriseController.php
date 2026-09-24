<?php

namespace App\Http\Controllers\Api\V1\Entreprise;

use App\Http\Controllers\Controller;
use App\Models\Enterprise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EnterpriseController extends Controller
{
    protected function formatEnterprise(Enterprise $enterprise): array
    {
        $base = request()->getSchemeAndHttpHost();

        return [
            'id'              => $enterprise->id,
            'name'            => $enterprise->name,
            'email'           => $enterprise->email,
            'phone'           => $enterprise->phone,
            'tax_number'      => $enterprise->tax_number,
            'address'         => $enterprise->address,
            'logo'            => $enterprise->logo,
            'logo_url'        => $enterprise->logo
                ? "{$base}/storage/logos/{$enterprise->logo}"
                : null,
            'status'          => $enterprise->status ?? 'ACTIVE',
            'employees_count' => $enterprise->employees_count ?? 0,
            'created_at'      => $enterprise->created_at,
            'updated_at'      => $enterprise->updated_at,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Enterprise::withCount('employees');

        if ($search = $request->input('search')) {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like) {
                $q->where('name', 'LIKE', $like)
                  ->orWhere('email', 'LIKE', $like)
                  ->orWhere('phone', 'LIKE', $like)
                  ->orWhere('tax_number', 'LIKE', $like)
                  ->orWhere('address', 'LIKE', $like);
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sortable = [
            'name'            => 'name',
            'email'           => 'email',
            'phone'           => 'phone',
            'tax_number'      => 'tax_number',
            'status'          => 'status',
            'created_at'      => 'created_at',
            'employees_count' => 'employees_count',
        ];

        $sortBy    = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        if (array_key_exists($sortBy, $sortable)) {
            $query->orderBy($sortable[$sortBy], $sortOrder);
        } else {
            $query->orderByDesc('created_at');
        }

        $perPage     = min((int) $request->input('per_page', 20), 300);
        $enterprises = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => [
                'entreprises' => $enterprises->getCollection()->map(fn ($e) => $this->formatEnterprise($e)),
                'pagination'  => [
                    'current_page' => $enterprises->currentPage(),
                    'last_page'    => $enterprises->lastPage(),
                    'per_page'     => $enterprises->perPage(),
                    'total'        => $enterprises->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'email'      => 'nullable|email|max:255',
            'phone'      => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:255',
            'address'    => 'nullable|string',
            'status'     => 'nullable|in:ACTIVE,INACTIVE',
        ]);

        $enterprise = Enterprise::create([
            'name'       => $validated['name'],
            'email'      => $validated['email'] ?? null,
            'phone'      => $validated['phone'] ?? null,
            'tax_number' => $validated['tax_number'] ?? null,
            'address'    => $validated['address'] ?? null,
            'status'     => $validated['status'] ?? 'ACTIVE',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Entreprise créée avec succès.',
            'data'    => [
                'entreprise' => $this->formatEnterprise($enterprise),
            ],
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $enterprise = Enterprise::withCount('employees')->find($id);

        if (!$enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'entreprise' => $this->formatEnterprise($enterprise),
            ],
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $enterprise = Enterprise::find($id);

        if (!$enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        $validated = $request->validate([
            'name'       => 'sometimes|required|string|max:255',
            'email'      => 'sometimes|nullable|email|max:255',
            'phone'      => 'sometimes|nullable|string|max:255',
            'tax_number' => 'sometimes|nullable|string|max:255',
            'address'    => 'sometimes|nullable|string',
            'status'     => 'sometimes|in:ACTIVE,INACTIVE,ARCHIVED',
        ]);

        $enterprise->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Entreprise mise à jour.',
            'data'    => [
                'entreprise' => $this->formatEnterprise($enterprise->fresh()),
            ],
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $enterprise = Enterprise::find($id);

        if (!$enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        $enterprise->delete();

        return response()->json([
            'success' => true,
            'message' => 'Entreprise supprimée.',
        ]);
    }

    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        $enterprise = Enterprise::find($id);

        if (!$enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:ACTIVE,INACTIVE',
        ]);

        $enterprise->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Statut mis à jour.',
            'data'    => [
                'entreprise' => $this->formatEnterprise($enterprise->fresh()),
            ],
        ]);
    }

    public function updateLogo(Request $request, string $id): JsonResponse
    {
        $enterprise = Enterprise::find($id);

        if (!$enterprise) {
            return response()->json([
                'success' => false,
                'message' => 'Entreprise introuvable.',
            ], 404);
        }

        $request->validate([
            'logo' => 'required|image|max:2048|mimes:jpg,jpeg,png,gif,webp',
        ]);

        $oldLogo = $enterprise->logo;
        if ($oldLogo && Storage::disk('public')->exists("logos/{$oldLogo}")) {
            Storage::disk('public')->delete("logos/{$oldLogo}");
        }

        $file = $request->file('logo');
        $extension = $file->getClientOriginalExtension();
        $filename  = Str::uuid() . ".{$extension}";

        $file->storeAs('logos', $filename, 'public');

        $enterprise->update(['logo' => $filename]);

        $base = request()->getSchemeAndHttpHost();
        $url  = "{$base}/storage/logos/{$filename}";

        return response()->json([
            'success' => true,
            'message' => 'Logo mis à jour.',
            'data'    => [
                'logo'       => $filename,
                'logo_url'   => $url,
                'avatar_url' => $url,
                'entreprise' => $this->formatEnterprise($enterprise->fresh()),
            ],
        ]);
    }
}
