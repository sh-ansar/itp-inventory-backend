<?php

namespace App\Http\Controllers\Api;

use App\Domain\Inventory\CompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ResponsiblePersonController extends Controller
{
    private function rules(Company $company, ?string $id = null): array
    {
        return [
            'code' => ['required', 'string', 'min:1', 'max:80',
                Rule::unique('responsible_people', 'code')->where('company_id', $company->id)->ignore($id, 'id')],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'position' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            ...($id ? ['version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    public function index(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->member($request, $company);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', Rule::in(['0', '1'])],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $q = $company->responsiblePeople()->withCount('assets');
        if (! empty($data['search'])) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($data['search'])));
            $pattern = '%'.$escaped.'%';
            $q->where(fn ($query) => $query
                ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(code) LIKE ? ESCAPE '!'", [$pattern]));
        }
        if (isset($data['active'])) {
            $q->where('is_active', $data['active'] === '1');
        }

        return response()->json($q->orderBy('name')->orderBy('id')
            ->paginate($data['per_page'] ?? 25));
    }

    public function store(Request $request, Company $company, CompanyAccess $access): JsonResponse
    {
        $access->canWrite($request, $company);
        $data = $request->validate($this->rules($company));
        $model = $company->responsiblePeople()->create([
            ...$data, 'id' => (string) Str::uuid(), 'source' => 'manual',
        ]);

        return response()->json(['data' => $model->refresh()], 201);
    }

    public function update(
        Request $request, Company $company, string $person, CompanyAccess $access
    ): JsonResponse {
        $access->canWrite($request, $company);
        $model = DB::transaction(function () use ($request, $company, $person) {
            $model = $company->responsiblePeople()->whereKey($person)->lockForUpdate()->firstOrFail();
            abort_unless($model->source === 'manual', 403, 'HRM-synced entries are read-only');
            $data = $request->validate($this->rules($company, $model->id));
            abort_unless($model->version === $data['version'], 409, 'Person was changed; reload');
            if (array_key_exists('is_active', $data) && ! $data['is_active']
                && ($model->assets()->where('status', 'active')->exists()
                    || $model->facilityNodes()->exists())) {
                throw ValidationException::withMessages([
                    'is_active' => ['Reassign active assets and facilities before deactivating a person'],
                ]);
            }
            $model->fill(collect($data)->except('version')->all());
            if ($model->isDirty()) {
                $model->version++;
                $model->save();
            }

            return $model->refresh();
        }, 3);

        return response()->json(['data' => $model]);
    }
}
