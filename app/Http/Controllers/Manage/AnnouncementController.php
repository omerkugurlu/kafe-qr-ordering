<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Cafe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(Request $request): View
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);

        $announcements = $cafe->announcements()
            ->with('branch')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get();

        return view('manage.announcements', [
            'cafe' => $cafe,
            'branches' => $cafe->branches()->orderBy('name')->get(),
            'announcements' => $announcements,
            'editing' => $request->integer('duzenle') > 0
                ? $cafe->announcements()->find($request->integer('duzenle'))
                : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);
        $validated = $this->validated($request, $cafe);

        $announcement = DB::transaction(function () use ($cafe, $request, $validated): Announcement {
            $announcement = $cafe->announcements()->create([
                ...$validated,
                'is_active' => $request->boolean('is_active'),
                'created_by' => $request->user()->id,
            ]);

            $this->log($request, $announcement, 'announcement.created');

            return $announcement;
        });

        return redirect()->route('manage.announcements.index')->with('status', '“'.$announcement->title.'” yayına eklendi.');
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null && $announcement->cafe_id === $cafe->id, 404);
        $validated = $this->validated($request, $cafe);

        DB::transaction(function () use ($request, $announcement, $validated): void {
            $announcement->forceFill([
                ...$validated,
                'is_active' => $request->boolean('is_active'),
            ])->save();

            $this->log($request, $announcement, 'announcement.updated');
        });

        return redirect()->route('manage.announcements.index')->with('status', 'Duyuru güncellendi.');
    }

    public function toggle(Request $request, Announcement $announcement): RedirectResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null && $announcement->cafe_id === $cafe->id, 404);

        DB::transaction(function () use ($request, $announcement): void {
            $announcement->forceFill(['is_active' => ! $announcement->is_active])->save();
            $this->log($request, $announcement, 'announcement.toggled');
        });

        return redirect()->route('manage.announcements.index')
            ->with('status', $announcement->is_active ? 'Duyuru yayına alındı.' : 'Duyuru yayından kaldırıldı.');
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null && $announcement->cafe_id === $cafe->id, 404);

        DB::transaction(function () use ($request, $announcement): void {
            $this->log($request, $announcement, 'announcement.deleted');
            $announcement->delete();
        });

        return redirect()->route('manage.announcements.index')->with('status', 'Duyuru silindi.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Cafe $cafe): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::in([
                Announcement::TYPE_ANNOUNCEMENT,
                Announcement::TYPE_EVENT,
                Announcement::TYPE_CAMPAIGN,
            ])],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('cafe_id', $cafe->id)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    private function log(Request $request, Announcement $announcement, string $action): void
    {
        ActivityLog::query()->create([
            'cafe_id' => $announcement->cafe_id,
            'branch_id' => $announcement->branch_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => Announcement::class,
            'subject_id' => $announcement->id,
            'metadata' => ['title' => $announcement->title, 'is_active' => $announcement->is_active],
            'ip_address' => $request->ip(),
        ]);
    }
}
