<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cafe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request): View
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);

        return view('manage.settings', [
            'cafe' => $cafe,
            'branches' => $cafe->branches()->withCount('diningTables')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'brand_name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'instagram' => ['nullable', 'string', 'max:120'],
            'registration_policy' => ['required', Rule::in([Cafe::POLICY_GUEST, Cafe::POLICY_OPTIONAL, Cafe::POLICY_REQUIRED])],
            'loyalty_enabled' => ['sometimes', 'boolean'],
            'points_per_100' => ['required', 'integer', 'min:1', 'max:100'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'cover' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'logo_url' => ['nullable', 'url', 'max:2048'],
            'cover_url' => ['nullable', 'url', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_cover' => ['sometimes', 'boolean'],
        ]);

        $attributes = [
            'name' => $validated['name'],
            'brand_name' => $validated['brand_name'] ?? null,
            'description' => $validated['description'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'instagram' => $validated['instagram'] ?? null,
            'registration_policy' => $validated['registration_policy'],
            'loyalty_enabled' => $request->boolean('loyalty_enabled'),
            'points_per_100' => $validated['points_per_100'],
        ];

        $attributes['logo_path'] = $this->resolveImage(
            $cafe->logo_path,
            $request->file('logo'),
            $validated['logo_url'] ?? null,
            $request->boolean('remove_logo'),
            'cafe/logos',
        );
        $attributes['cover_path'] = $this->resolveImage(
            $cafe->cover_path,
            $request->file('cover'),
            $validated['cover_url'] ?? null,
            $request->boolean('remove_cover'),
            'cafe/covers',
        );

        $cafe->fill($attributes)->save();

        ActivityLog::query()->create([
            'cafe_id' => $cafe->id,
            'user_id' => $request->user()->id,
            'action' => 'cafe.settings_updated',
            'subject_type' => Cafe::class,
            'subject_id' => $cafe->id,
            'metadata' => [
                'registration_policy' => $cafe->registration_policy,
                'loyalty_enabled' => $cafe->loyalty_enabled,
                'brand_name' => $cafe->brand_name,
            ],
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('manage.settings.index')->with('status', 'Kafe ayarları güncellendi.');
    }

    private function resolveImage(
        ?string $current,
        ?UploadedFile $file,
        ?string $url,
        bool $remove,
        string $directory,
    ): ?string {
        if ($file !== null) {
            $this->deleteLocalFile($current);

            return $file->store($directory, 'public');
        }

        if ($remove) {
            $this->deleteLocalFile($current);

            return null;
        }

        if (filled($url)) {
            $this->deleteLocalFile($current);

            return $url;
        }

        return $current;
    }

    private function deleteLocalFile(?string $path): void
    {
        if ($path !== null && $path !== '' && ! Str::startsWith($path, ['http://', 'https://'])) {
            Storage::disk('public')->delete($path);
        }
    }
}
