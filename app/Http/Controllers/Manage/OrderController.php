<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);

        $branches = $cafe->branches()->orderBy('name')->get();
        $branch = $branches->firstWhere('id', (int) $request->query('sube'));
        $branchIds = $branch !== null ? collect([$branch->id]) : $branches->pluck('id');
        $status = $request->query('durum');
        $search = trim((string) $request->query('q'));

        $orders = Order::query()
            ->with(['tableSession.diningTable', 'items.options'])
            ->whereIn('branch_id', $branchIds)
            ->when(in_array($status, [
                Order::STATUS_PENDING,
                Order::STATUS_ACCEPTED,
                Order::STATUS_READY,
                Order::STATUS_SERVED,
                Order::STATUS_PAID,
                Order::STATUS_CANCELLED,
            ], true), fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('order_number', 'like', '%'.$search.'%')
                        ->orWhereHas('tableSession.diningTable', fn ($table) => $table->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $counts = Order::query()
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('manage.orders', [
            'cafe' => $cafe,
            'branches' => $branches,
            'branch' => $branch,
            'orders' => $orders,
            'counts' => $counts,
            'status' => $status,
            'search' => $search,
        ]);
    }
}
