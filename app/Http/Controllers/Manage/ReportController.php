<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);

        [$from, $to, $branch, $branches, $branchIds] = $this->filters($request);
        $report = $this->buildReport($branchIds, $from, $to);

        return view('manage.reports', [
            'cafe' => $cafe,
            'branches' => $branches,
            'branch' => $branch,
            'from' => $from,
            'to' => $to,
            'report' => $report,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);

        [$from, $to, $branch, , $branchIds] = $this->filters($request);

        $orders = Order::query()
            ->with(['tableSession.diningTable', 'branch', 'items'])
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('created_at')
            ->get();

        $filename = 'skykite-rapor-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($orders): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Sipariş No', 'Tarih', 'Şube', 'Masa', 'Kaynak', 'Durum',
                'Ara Toplam', 'İndirim', 'Toplam', 'Kupon', 'Ürünler',
            ], ';');

            foreach ($orders as $order) {
                $row = [
                    $order->order_number,
                    $order->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
                    $order->branch?->name,
                    $order->tableSession?->diningTable?->name,
                    $order->source === 'waiter' ? 'Garson' : 'QR',
                    $order->status,
                    number_format((float) $order->subtotal, 2, ',', ''),
                    number_format((float) $order->discount_total, 2, ',', ''),
                    number_format((float) $order->total, 2, ',', ''),
                    $order->coupon_code,
                    $order->items->map(fn ($item): string => $item->quantity.'× '.$item->name_snapshot)->implode(' | '),
                ];

                fputcsv($handle, array_map(self::safeSpreadsheetCell(...), $row), ';');
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function safeSpreadsheetCell(mixed $value): string
    {
        $cell = (string) ($value ?? '');
        $hasFormulaPrefix = preg_match('/^[\x00-\x20\x7F]*[=+\-@]/', $cell) === 1
            || preg_match('/^(?:\s|\p{Z}|\p{C})*[=+\-@]/u', $cell) === 1;

        return $hasFormulaPrefix ? "'".$cell : $cell;
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: mixed, 3: Collection, 4: Collection}
     */
    private function filters(Request $request): array
    {
        $cafe = $request->user()->cafe;
        $branches = $cafe->branches()->orderBy('name')->get();
        $branch = $branches->firstWhere('id', (int) $request->query('sube'));
        $branchIds = $branch !== null ? collect([$branch->id]) : $branches->pluck('id');

        $from = $this->parseDate($request->query('baslangic')) ?? today()->subDays(29);
        $to = $this->parseDate($request->query('bitis')) ?? today();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        return [$from->startOfDay(), $to->endOfDay(), $branch, $branches, $branchIds];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $value)?->startOfDay();
    }

    /**
     * @param  Collection<int, int>  $branchIds
     * @return array<string, mixed>
     */
    private function buildReport(Collection $branchIds, Carbon $from, Carbon $to): array
    {
        $payments = Payment::query()
            ->whereNull('voided_at')
            ->whereBetween('recorded_at', [$from, $to])
            ->whereHas('tableSession', fn ($query) => $query->whereIn('branch_id', $branchIds));

        $revenueCents = Money::toCents((string) (clone $payments)->sum('amount'));
        $paymentBreakdown = (clone $payments)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('method')
            ->get()
            ->map(fn ($row): array => [
                'method' => $row->method,
                'label' => $row->method === Payment::METHOD_CASH ? 'Nakit' : 'Kart',
                'cents' => Money::toCents((string) $row->total),
                'count' => (int) $row->count,
            ]);

        $orders = Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotIn('status', [Order::STATUS_CANCELLED]);

        $orderCount = (clone $orders)->count();
        $ordersTotalCents = Money::toCents((string) (clone $orders)->sum('total'));
        $discountCents = Money::toCents((string) (clone $orders)->sum('discount_total'));
        $cancelledCount = Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', Order::STATUS_CANCELLED)
            ->count();

        $daily = collect(range(0, $from->diffInDays($to)))
            ->map(function (int $offset) use ($from): Carbon {
                return $from->copy()->addDays($offset);
            })
            ->map(function (Carbon $day) use ($branchIds): array {
                $revenue = Payment::query()
                    ->whereNull('voided_at')
                    ->whereDate('recorded_at', $day)
                    ->whereHas('tableSession', fn ($query) => $query->whereIn('branch_id', $branchIds))
                    ->sum('amount');

                $count = Order::query()
                    ->whereIn('branch_id', $branchIds)
                    ->whereDate('created_at', $day)
                    ->whereNotIn('status', [Order::STATUS_CANCELLED])
                    ->count();

                return [
                    'date' => $day,
                    'label' => $day->format('d.m'),
                    'cents' => Money::toCents((string) $revenue),
                    'orders' => $count,
                ];
            });

        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.branch_id', $branchIds)
            ->whereBetween('orders.created_at', [$from, $to])
            ->whereNotIn('orders.status', [Order::STATUS_CANCELLED])
            ->select(
                'order_items.name_snapshot',
                DB::raw('SUM(order_items.quantity) as quantity'),
                DB::raw('SUM(order_items.line_total) as revenue'),
            )
            ->groupBy('order_items.name_snapshot')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'name' => $row->name_snapshot,
                'quantity' => (int) $row->quantity,
                'cents' => Money::toCents((string) $row->revenue),
            ]);

        $couponOrders = (clone $orders)->whereNotNull('coupon_id')->count();

        return [
            'revenue_cents' => $revenueCents,
            'order_count' => $orderCount,
            'orders_total_cents' => $ordersTotalCents,
            'average_cents' => $orderCount > 0 ? intdiv($ordersTotalCents, $orderCount) : 0,
            'discount_cents' => $discountCents,
            'cancelled_count' => $cancelledCount,
            'coupon_orders' => $couponOrders,
            'payments' => $paymentBreakdown,
            'daily' => $daily,
            'max_daily' => max(1, (int) $daily->max('cents')),
            'top_products' => $topProducts,
            'days' => (int) $from->diffInDays($to) + 1,
        ];
    }
}
