<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DiningTable;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class QrCodeController extends Controller
{
    public function show(Branch $branch, DiningTable $table): Response
    {
        Gate::authorize('view', $branch);
        abort_unless($table->branch_id === $branch->id && $table->is_active, 404);
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'svgViewBoxSize' => 10,
            'drawCircularModules' => false,
            'addQuietzone' => true,
        ]);
        $svg = (new QRCode($options))->render(route('customer.menu', $table->token()));

        return response($svg)->header('Content-Type', 'image/svg+xml')->header('Cache-Control', 'private, no-store');
    }

    public function print(Branch $branch, DiningTable $table): View
    {
        Gate::authorize('view', $branch);
        abort_unless($table->branch_id === $branch->id && $table->is_active, 404);

        return view('manage.table-qr', compact('branch', 'table'));
    }
}
