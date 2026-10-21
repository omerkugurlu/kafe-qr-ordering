<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredCustomerController;
use App\Http\Controllers\Customer\MenuController;
use App\Http\Controllers\Customer\OrderController;
use App\Http\Controllers\Manage\BranchController;
use App\Http\Controllers\Manage\DiningTableController;
use App\Http\Controllers\Manage\LoyaltyCampaignController;
use App\Http\Controllers\Manage\MenuCategoryController;
use App\Http\Controllers\Manage\MenuProductController;
use App\Http\Controllers\Manage\QrCodeController;
use App\Http\Controllers\Manage\SettingsController;
use App\Http\Controllers\Manage\StaffController;
use App\Http\Controllers\Platform\CafeController;
use App\Http\Controllers\Staff\PaymentController;
use App\Http\Controllers\Staff\TableSessionController;
use App\Models\Branch;
use App\Models\Cafe;
use App\Models\Order;
use App\Models\TableSession;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('home'))->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/giris', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/giris', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/masa/{token}/kayit', [RegisteredCustomerController::class, 'create'])->name('customer.register');
    Route::post('/masa/{token}/kayit', [RegisteredCustomerController::class, 'store'])->middleware('throttle:login')->name('customer.register.store');
    Route::get('/sifre/sifirla', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/sifre/sifirla', [PasswordResetController::class, 'sendLink'])->middleware('throttle:login')->name('password.email');
    Route::get('/sifre/yeni/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/sifre/yeni', [PasswordResetController::class, 'reset'])->middleware('throttle:login')->name('password.update');
});

Route::post('/cikis', [AuthenticatedSessionController::class, 'destroy'])->middleware(['auth', 'auth.session'])->name('logout');

Route::get('/masa/{token}', [MenuController::class, 'show'])->middleware(['auth.session', 'throttle:qr-menu'])->name('customer.menu');
Route::post('/masa/{token}/siparis', [OrderController::class, 'store'])->middleware(['auth.session', 'throttle:qr-order'])->name('customer.orders.store');

Route::middleware(['auth', 'auth.session', 'role:platform_admin'])->prefix('platform')->name('platform.')->group(function (): void {
    Route::get('/', [CafeController::class, 'index'])->name('dashboard');
    Route::post('/cafeler', [CafeController::class, 'store'])->middleware('throttle:admin-write')->name('cafes.store');
    Route::patch('/cafeler/{cafe}', [CafeController::class, 'update'])->middleware('throttle:admin-write')->name('cafes.update');
    Route::patch('/cafeler/{cafe}/sahip', [CafeController::class, 'updateOwner'])->middleware('throttle:admin-write')->name('cafes.owner.update');
    Route::patch('/cafeler/{cafe}/durum', [CafeController::class, 'toggleStatus'])->middleware('throttle:admin-write')->name('cafes.status');
});

Route::middleware(['auth', 'auth.session', 'role:cafe_owner,cafe_manager'])->prefix('yonetim')->name('manage.')->group(function (): void {
    Route::get('/', function () {
        $cafe = auth()->user()->cafe;

        return view('manage.dashboard', [
            'cafe' => $cafe,
            'branches' => $cafe->branches()->withCount('diningTables')->orderBy('name')->get(),
            'productsCount' => $cafe->products()->count(),
            'categoriesCount' => $cafe->categories()->count(),
        ]);
    })->name('dashboard');

    Route::post('/subeler', [BranchController::class, 'store'])->middleware('throttle:admin-write')->name('branches.store');
    Route::get('/subeler/{branch}', [BranchController::class, 'show'])->name('branches.show');
    Route::patch('/subeler/{branch}', [BranchController::class, 'update'])->middleware('throttle:admin-write')->name('branches.update');
    Route::post('/subeler/{branch}/menu-kopyala', [BranchController::class, 'copyMenu'])->middleware('throttle:admin-write')->name('branches.menu-copy');
    Route::post('/subeler/{branch}/kategoriler', [MenuCategoryController::class, 'store'])->middleware('throttle:admin-write')->name('categories.store');
    Route::patch('/subeler/{branch}/kategoriler/{categoryId}', [MenuCategoryController::class, 'update'])->middleware('throttle:admin-write')->name('categories.update');
    Route::delete('/subeler/{branch}/kategoriler/{categoryId}', [MenuCategoryController::class, 'destroy'])->middleware('throttle:admin-write')->name('categories.destroy');
    Route::post('/subeler/{branch}/urunler', [MenuProductController::class, 'store'])->middleware('throttle:admin-write')->name('products.store');
    Route::patch('/subeler/{branch}/urunler/{productId}', [MenuProductController::class, 'update'])->middleware('throttle:admin-write')->name('products.update');
    Route::post('/subeler/{branch}/urunler/{productId}/secenek-gruplari', [MenuProductController::class, 'storeOptionGroup'])->middleware('throttle:admin-write')->name('products.option-groups.store');
    Route::patch('/subeler/{branch}/urunler/{productId}/secenek-gruplari/{groupId}', [MenuProductController::class, 'updateOptionGroup'])->middleware('throttle:admin-write')->name('products.option-groups.update');
    Route::delete('/subeler/{branch}/urunler/{productId}/secenek-gruplari/{groupId}', [MenuProductController::class, 'destroyOptionGroup'])->middleware('throttle:admin-write')->name('products.option-groups.destroy');
    Route::patch('/subeler/{branch}/urunler/{productId}/sube', [MenuProductController::class, 'updateBranchOverride'])->middleware('throttle:admin-write')->name('products.branch-update');
    Route::delete('/subeler/{branch}/urunler/{productId}', [MenuProductController::class, 'destroy'])->middleware('throttle:admin-write')->name('products.destroy');
    Route::get('/subeler/{branch}/masalar', [DiningTableController::class, 'index'])->name('tables.index');
    Route::post('/subeler/{branch}/masalar', [DiningTableController::class, 'store'])->middleware('throttle:admin-write')->name('tables.store');
    Route::patch('/subeler/{branch}/masalar/{table}', [DiningTableController::class, 'update'])->middleware('throttle:admin-write')->name('tables.update');
    Route::post('/subeler/{branch}/masalar/{table}/qr-yenile', [DiningTableController::class, 'rotate'])->middleware('throttle:admin-write')->name('tables.rotate');
    Route::get('/subeler/{branch}/masalar/{table}/qr.svg', [QrCodeController::class, 'show'])->name('tables.qr');
    Route::get('/subeler/{branch}/masalar/{table}/qr-yazdir', [QrCodeController::class, 'print'])->name('tables.print');
    Route::patch('/ayarlar', [SettingsController::class, 'update'])->middleware('throttle:admin-write')->name('settings.update');
    Route::get('/calisanlar', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/calisanlar', [StaffController::class, 'store'])->middleware('throttle:admin-write')->name('staff.store');
    Route::patch('/calisanlar/{user}', [StaffController::class, 'update'])->middleware('throttle:admin-write')->name('staff.update');
    Route::get('/sadakat', [LoyaltyCampaignController::class, 'index'])->name('loyalty.index');
    Route::post('/sadakat', [LoyaltyCampaignController::class, 'store'])->middleware('throttle:admin-write')->name('loyalty.store');
    Route::patch('/sadakat/{campaign}', [LoyaltyCampaignController::class, 'update'])->middleware('throttle:admin-write')->name('loyalty.update');
});

Route::middleware(['auth', 'auth.session', 'role:waiter'])->prefix('garson')->name('staff.')->group(function (): void {
    Route::get('/', function () {
        $branches = auth()->user()->branches()
            ->where('branches.is_active', true)
            ->whereHas('cafe', fn ($query) => $query->where('status', Cafe::STATUS_ACTIVE))
            ->withCount(['orders as pending_orders_count' => fn ($query) => $query->where('status', Order::STATUS_PENDING)])
            ->withCount(['tableSessions as open_sessions_count' => fn ($query) => $query->where('status', TableSession::STATUS_OPEN)])
            ->orderBy('branches.name')->get();

        return view('staff.dashboard', compact('branches'));
    })->name('orders');

    Route::get('/sube/{branch}', function (Branch $branch) {
        Gate::authorize('view', $branch);

        return view('staff.branch', compact('branch'));
    })->name('branch');
    Route::get('/adisyon/{sessionId}', [TableSessionController::class, 'show'])->name('sessions.show');
    Route::post('/adisyon/{sessionId}/urun-ekle', [TableSessionController::class, 'addItems'])->middleware('throttle:admin-write')->name('sessions.items.store');
    Route::post('/adisyon/{sessionId}/odemeler', [PaymentController::class, 'store'])->middleware('throttle:admin-write')->name('sessions.payments.store');
    Route::post('/adisyon/{sessionId}/odemeler/{paymentId}/iptal', [PaymentController::class, 'void'])->middleware('throttle:admin-write')->name('sessions.payments.void');
    Route::post('/adisyon/{sessionId}/kapat', [TableSessionController::class, 'close'])->middleware('throttle:admin-write')->name('sessions.close');
});
