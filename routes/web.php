<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\CustomerSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredCustomerController;
use App\Http\Controllers\Customer\MenuController;
use App\Http\Controllers\Customer\OrderController;
use App\Http\Controllers\Manage\AnnouncementController;
use App\Http\Controllers\Manage\BranchController;
use App\Http\Controllers\Manage\CouponController;
use App\Http\Controllers\Manage\DashboardController;
use App\Http\Controllers\Manage\DiningTableController;
use App\Http\Controllers\Manage\LoyaltyCampaignController;
use App\Http\Controllers\Manage\MenuCategoryController;
use App\Http\Controllers\Manage\MenuProductController;
use App\Http\Controllers\Manage\OrderController as ManageOrderController;
use App\Http\Controllers\Manage\QrCodeController;
use App\Http\Controllers\Manage\ReportController;
use App\Http\Controllers\Manage\SettingsController;
use App\Http\Controllers\Manage\StaffController;
use App\Http\Controllers\Platform\CafeController;
use App\Http\Controllers\Staff\BoardController;
use App\Http\Controllers\Staff\KitchenController;
use App\Http\Controllers\Staff\PaymentController;
use App\Http\Controllers\Staff\TableSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('home'))->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/giris', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/giris', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/sifre/sifirla', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/sifre/sifirla', [PasswordResetController::class, 'sendLink'])->middleware('throttle:login')->name('password.email');
    Route::get('/sifre/yeni/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/sifre/yeni', [PasswordResetController::class, 'reset'])->middleware('throttle:login')->name('password.update');
});

Route::get('/masa/{token}/giris', [CustomerSessionController::class, 'create'])->name('customer.login');
Route::post('/masa/{token}/giris', [CustomerSessionController::class, 'store'])->middleware('throttle:login')->name('customer.login.store');
Route::get('/masa/{token}/kayit', [RegisteredCustomerController::class, 'create'])->name('customer.register');
Route::post('/masa/{token}/kayit', [RegisteredCustomerController::class, 'store'])->middleware('throttle:login')->name('customer.register.store');

Route::post('/cikis', [AuthenticatedSessionController::class, 'destroy'])->middleware(['auth', 'auth.session'])->name('logout');

Route::get('/dil/{locale}', function (string $locale) {
    abort_unless(in_array($locale, ['tr', 'en'], true), 404);
    session(['locale' => $locale]);
    app()->setLocale($locale);

    return redirect()->back();
})->name('locale.switch');

Route::get('/masa/{token}', [MenuController::class, 'show'])->middleware(['auth.session', 'throttle:qr-menu'])->name('customer.menu');
Route::post('/masa/{token}/siparis', [OrderController::class, 'store'])->middleware(['auth.session', 'throttle:qr-order'])->name('customer.orders.store');
Route::get('/masa/{token}/siparis/{orderNumber}', [OrderController::class, 'track'])->middleware(['auth.session', 'throttle:customer-poll'])->name('customer.order.track');
Route::get('/masa/{token}/adisyon', [OrderController::class, 'bill'])->middleware(['auth.session', 'throttle:customer-poll'])->name('customer.bill');
Route::get('/masa/{token}/durum', [OrderController::class, 'status'])->middleware(['auth.session', 'throttle:customer-poll'])->name('customer.status');
Route::post('/masa/{token}/kupon', [OrderController::class, 'checkCoupon'])->middleware(['auth.session', 'throttle:coupon-check'])->name('customer.coupon.check');
Route::post('/masa/{token}/cagri', [OrderController::class, 'callWaiter'])->middleware(['auth.session', 'throttle:service-request'])->name('customer.call');

Route::middleware(['auth', 'auth.session', 'role:platform_admin'])->prefix('platform')->name('platform.')->group(function (): void {
    Route::get('/', [CafeController::class, 'index'])->name('dashboard');
    Route::get('/cafeler/{cafe}', [CafeController::class, 'show'])->name('cafes.show');
    Route::post('/cafeler', [CafeController::class, 'store'])->middleware('throttle:admin-write')->name('cafes.store');
    Route::patch('/cafeler/{cafe}', [CafeController::class, 'update'])->middleware('throttle:admin-write')->name('cafes.update');
    Route::patch('/cafeler/{cafe}/sahip', [CafeController::class, 'updateOwner'])->middleware('throttle:admin-write')->name('cafes.owner.update');
    Route::patch('/cafeler/{cafe}/durum', [CafeController::class, 'toggleStatus'])->middleware('throttle:admin-write')->name('cafes.status');
});

Route::middleware(['auth', 'auth.session', 'role:cafe_owner,cafe_manager'])->prefix('yonetim')->name('manage.')->group(function (): void {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/siparisler', [ManageOrderController::class, 'index'])->name('orders.index');
    Route::get('/siparisler/akis/{branch}', [BoardController::class, 'data'])->middleware('throttle:staff-poll')->name('orders.data');
    Route::post('/siparisler/{order}/durum', [BoardController::class, 'updateStatus'])->middleware('throttle:admin-write')->name('orders.status');
    Route::post('/siparisler/cagri/{serviceRequest}/tamam', [BoardController::class, 'resolveServiceRequest'])->middleware('throttle:admin-write')->name('orders.requests.resolve');

    Route::get('/kuponlar', [CouponController::class, 'index'])->name('coupons.index');
    Route::post('/kuponlar', [CouponController::class, 'store'])->middleware('throttle:admin-write')->name('coupons.store');
    Route::patch('/kuponlar/{coupon}', [CouponController::class, 'update'])->middleware('throttle:admin-write')->name('coupons.update');
    Route::delete('/kuponlar/{coupon}', [CouponController::class, 'destroy'])->middleware('throttle:admin-write')->name('coupons.destroy');

    Route::get('/raporlar', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/raporlar/csv', [ReportController::class, 'export'])->name('reports.export');

    Route::get('/ayarlar', [SettingsController::class, 'index'])->name('settings.index');
    Route::patch('/ayarlar', [SettingsController::class, 'update'])->middleware('throttle:admin-write')->name('settings.update');

    Route::get('/duyurular', [AnnouncementController::class, 'index'])->name('announcements.index');
    Route::post('/duyurular', [AnnouncementController::class, 'store'])->middleware('throttle:admin-write')->name('announcements.store');
    Route::patch('/duyurular/{announcement}', [AnnouncementController::class, 'update'])->middleware('throttle:admin-write')->name('announcements.update');
    Route::patch('/duyurular/{announcement}/durum', [AnnouncementController::class, 'toggle'])->middleware('throttle:admin-write')->name('announcements.toggle');
    Route::delete('/duyurular/{announcement}', [AnnouncementController::class, 'destroy'])->middleware('throttle:admin-write')->name('announcements.destroy');

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
    Route::get('/calisanlar', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/calisanlar', [StaffController::class, 'store'])->middleware('throttle:admin-write')->name('staff.store');
    Route::patch('/calisanlar/{user}', [StaffController::class, 'update'])->middleware('throttle:admin-write')->name('staff.update');
    Route::get('/sadakat', [LoyaltyCampaignController::class, 'index'])->name('loyalty.index');
    Route::post('/sadakat', [LoyaltyCampaignController::class, 'store'])->middleware('throttle:admin-write')->name('loyalty.store');
    Route::patch('/sadakat/{campaign}', [LoyaltyCampaignController::class, 'update'])->middleware('throttle:admin-write')->name('loyalty.update');
});

Route::middleware(['auth', 'auth.session', 'role:waiter,kitchen'])->prefix('garson')->name('staff.')->group(function (): void {
    Route::get('/', [BoardController::class, 'index'])->name('orders');
    Route::get('/sube/{branch}', [BoardController::class, 'branch'])->name('branch');
    Route::get('/sube/{branch}/akis', [BoardController::class, 'data'])->middleware('throttle:staff-poll')->name('board.data');
    Route::post('/siparis/{order}/durum', [BoardController::class, 'updateStatus'])->middleware('throttle:admin-write')->name('orders.status');
    Route::post('/cagri/{serviceRequest}/tamam', [BoardController::class, 'resolveServiceRequest'])->middleware('throttle:admin-write')->name('service-requests.resolve');
});

Route::middleware(['auth', 'auth.session', 'role:waiter,cafe_owner,cafe_manager'])->prefix('garson')->name('staff.')->group(function (): void {
    Route::get('/adisyon/{sessionId}', [TableSessionController::class, 'show'])->name('sessions.show');
    Route::post('/adisyon/{sessionId}/urun-ekle', [TableSessionController::class, 'addItems'])->middleware('throttle:admin-write')->name('sessions.items.store');
    Route::post('/adisyon/{sessionId}/odemeler', [PaymentController::class, 'store'])->middleware('throttle:admin-write')->name('sessions.payments.store');
    Route::post('/adisyon/{sessionId}/odemeler/{paymentId}/iptal', [PaymentController::class, 'void'])->middleware('throttle:admin-write')->name('sessions.payments.void');
    Route::post('/adisyon/{sessionId}/kapat', [TableSessionController::class, 'close'])->middleware('throttle:admin-write')->name('sessions.close');
});

Route::middleware(['auth', 'auth.session', 'role:waiter,kitchen,cafe_owner,cafe_manager'])
    ->get('/mutfak/{branch?}', [KitchenController::class, 'index'])
    ->name('staff.kitchen');
