<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\DiningTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DiningTableController extends Controller
{
    public function index(Branch $branch): View
    {
        Gate::authorize('view', $branch);

        return view('manage.tables', [
            'branch' => $branch,
            'tables' => $branch->diningTables()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('dining_tables', 'name')->where('branch_id', $branch->id)->whereNull('deleted_at'),
            ],
        ]);
        $table = DiningTable::createWithToken(['branch_id' => $branch->id, 'name' => $validated['name'], 'is_active' => true]);
        ActivityLog::query()->create([
            'cafe_id' => $branch->cafe_id,
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'action' => 'dining_table.created',
            'subject_type' => DiningTable::class,
            'subject_id' => $table->id,
            'metadata' => ['name' => $table->name],
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('manage.tables.index', $branch)->with('status', 'Masa ve QR bağlantısı oluşturuldu.');
    }

    public function rotate(Request $request, Branch $branch, DiningTable $table): RedirectResponse
    {
        Gate::authorize('update', $branch);
        abort_unless($table->branch_id === $branch->id, 404);
        $token = $table->rotateToken();
        ActivityLog::query()->create([
            'cafe_id' => $branch->cafe_id,
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'action' => 'dining_table.qr_rotated',
            'subject_type' => DiningTable::class,
            'subject_id' => $table->id,
            'metadata' => ['name' => $table->name],
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('manage.tables.index', $branch)->with('status', 'QR kod yenilendi. Eski kod artık çalışmaz. Yeni bağlantı: '.route('customer.menu', $token));
    }

    public function update(Request $request, Branch $branch, DiningTable $table): RedirectResponse
    {
        Gate::authorize('update', $branch);
        abort_unless($table->branch_id === $branch->id, 404);
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $table->forceFill(['is_active' => (bool) $validated['is_active']])->save();

        return redirect()->route('manage.tables.index', $branch)->with('status', 'Masa durumu güncellendi.');
    }
}
