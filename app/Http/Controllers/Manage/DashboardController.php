<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\MenuProduct;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);

        $branches = $cafe->branches()->withCount('diningTables')->orderBy('name')->get();
        $branch = $branches->firstWhere('id', (int) $request->query('sube'));
        $branchIds = $branch !== null ? collect([$branch->id]) : $branches->pluck('id');

        $payments = Payment::query()
            ->whereNull('voided_at')
            ->whereHas('tableSession', fn ($query) => $query->whereIn('branch_id', $branchIds));

        $todayRevenue = Money::toCents((string) (clone $payments)->whereDate('recorded_at', today())->sum('amount'));
        $yesterdayRevenue = Money::toCents((string) (clone $payments)->whereDate('recorded_at', today()->subDay())->sum('amount'));

        $ordersToday = Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereDate('created_at', today())
            ->whereNotIn('status', [Order::STATUS_CANCELLED]);

        $ordersTodayCount = (clone $ordersToday)->count();
        $ordersTodayTotal = Money::toCents((string) (clone $ordersToday)->sum('total'));

        $openSessions = TableSession::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', TableSession::STATUS_OPEN)
            ->count();

        $pendingOrders = Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_ACCEPTED, Order::STATUS_READY])
            ->count();

        $staffOnDuty = $cafe->users()
            ->whereIn('role', [User::ROLE_WAITER, User::ROLE_KITCHEN])
            ->where('is_active', true)
            ->count();

        $dailySeries = $this->dailySeries($branchIds);
        $maxDaily = max(1, (int) $dailySeries->max('cents'));

        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.branch_id', $branchIds)
            ->whereNotIn('orders.status', [Order::STATUS_CANCELLED])
            ->where('orders.created_at', '>=', now()->subDays(30))
            ->select('order_items.name_snapshot', DB::raw('SUM(order_items.quantity) as quantity'), DB::raw('SUM(order_items.line_total) as revenue'))
            ->groupBy('order_items.name_snapshot')
            ->orderByDesc('quantity')
            ->limit(6)
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->name_snapshot,
                'quantity' => (int) $row->quantity,
                'revenue_cents' => Money::toCents((string) $row->revenue),
            ]);

        $recentOrders = Order::query()
            ->with(['tableSession.diningTable', 'items'])
            ->whereIn('branch_id', $branchIds)
            ->latest()
            ->limit(6)
            ->get();

        $productsCount = MenuProduct::query()->where('cafe_id', $cafe->id)->where('is_available', true)->count();

        return view('manage.dashboard', [
            'cafe' => $cafe,
            'branches' => $branches,
            'branch' => $branch,
            'todayRevenue' => $todayRevenue,
            'revenueDelta' => $yesterdayRevenue > 0 ? (int) round((($todayRevenue - $yesterdayRevenue) / $yesterdayRevenue) * 100) : null,
            'ordersTodayCount' => $ordersTodayCount,
            'averageBasket' => $ordersTodayCount > 0 ? intdiv($ordersTodayTotal, $ordersTodayCount) : 0,
            'openSessions' => $openSessions,
            'pendingOrders' => $pendingOrders,
            'staffOnDuty' => $staffOnDuty,
            'productsCount' => $productsCount,
            'dailySeries' => $dailySeries,
            'maxDaily' => $maxDaily,
            'topProducts' => $topProducts,
            'recentOrders' => $recentOrders,
        ]);
    }

    /** @param Collection<int, int> $branchIds */
    private function dailySeries(Collection $branchIds): Collection
    {
        $start = today()->subDays(6)->startOfDay();

        $revenue = Payment::query()
            ->whereNull('voided_at')
            ->where('recorded_at', '>=', $start)
            ->whereHas('tableSession', fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->selectRaw('DATE(recorded_at) as day, SUM(amount) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $orders = Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereNotIn('status', [Order::STATUS_CANCELLED])
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(0, 6))->map(function (int $offset) use ($start, $revenue, $orders): array {
            $day = $start->copy()->addDays($offset);
            $key = $day->toDateString();

            return [
                'date' => $day,
                'label' => $day->locale('tr')->isoFormat('ddd'),
                'short' => $day->format('d.m'),
                'cents' => Money::toCents((string) ($revenue[$key] ?? 0)),
                'orders' => (int) ($orders[$key] ?? 0),
                'is_today' => $day->isToday(),
            ];
        });
    }
}
