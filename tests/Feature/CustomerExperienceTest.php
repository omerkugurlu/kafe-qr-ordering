<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Services\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_only_sign_in_through_qr_and_can_use_the_same_account_at_two_cafes(): void
    {
        [$cafeA, $branchA, $tableA] = $this->cafeWithTable('Cafe 34', 'cafe-34');
        [$cafeB, $branchB, $tableB] = $this->cafeWithTable('Cafe Porto', 'cafe-porto');
        $customer = User::factory()->create([
            'name' => 'Ortak Müşteri',
            'email' => 'ortak@example.com',
            'password' => 'Customer!2026',
            'role' => User::ROLE_CUSTOMER,
            'cafe_id' => null,
        ]);

        $this->post(route('login.store'), [
            'email' => $customer->email,
            'password' => 'Customer!2026',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('customer.login.store', $tableA->token()), [
            'email' => $customer->email,
            'password' => 'Customer!2026',
        ])->assertRedirect(route('customer.menu', $tableA->token()));
        $this->assertAuthenticatedAs($customer);

        $orderA = $this->placeOrder($cafeA, $branchA, $tableA, 'Kahve', '200.00', 2);
        $orderB = $this->placeOrder($cafeB, $branchB, $tableB, 'Tatlı', '150.00', 2);
        $this->assertSame($customer->id, $orderA->customer_id);
        $this->assertSame($customer->id, $orderB->customer_id);

        $cafeA->forceFill(['loyalty_enabled' => true, 'points_per_100' => 1])->save();
        $cafeB->forceFill(['loyalty_enabled' => true, 'points_per_100' => 1])->save();
        $loyalty = app(LoyaltyService::class);
        $loyalty->earnForPaidOrder($orderA);
        $loyalty->earnForPaidOrder($orderB);

        $this->assertDatabaseHas('loyalty_accounts', [
            'cafe_id' => $cafeA->id,
            'user_id' => $customer->id,
            'points' => 4,
            'completed_order_count' => 1,
        ]);
        $this->assertDatabaseHas('loyalty_accounts', [
            'cafe_id' => $cafeB->id,
            'user_id' => $customer->id,
            'points' => 3,
            'completed_order_count' => 1,
        ]);
        $this->assertSame(2, LoyaltyAccount::query()->where('user_id', $customer->id)->count());
    }

    public function test_required_registration_redirects_to_qr_auth_and_registration_creates_a_global_customer(): void
    {
        [$cafe, , $table] = $this->cafeWithTable('Zorunlu Kafe', 'zorunlu-kafe');
        $cafe->forceFill(['registration_policy' => Cafe::POLICY_REQUIRED])->save();
        $token = $table->token();

        $this->get(route('customer.menu', $token))
            ->assertRedirect(route('customer.login', $token));
        $this->get(route('customer.login', $token))
            ->assertOk()
            ->assertSee('E-posta')
            ->assertSee('Hesap oluştur');

        $this->post(route('customer.register.store', $token), [
            'name' => 'Yeni Müşteri',
            'email' => 'yeni@example.com',
            'password' => 'Customer!2026',
            'password_confirmation' => 'Customer!2026',
        ])->assertRedirect(route('customer.menu', $token));

        $this->assertDatabaseHas('users', [
            'email' => 'yeni@example.com',
            'role' => User::ROLE_CUSTOMER,
            'cafe_id' => null,
        ]);
        $this->get(route('customer.menu', $token))->assertOk();
    }

    public function test_customer_registration_accepts_eight_character_passwords_and_rejects_shorter_ones(): void
    {
        [, , $table] = $this->cafeWithTable('Şifre Kafe', 'sifre-kafe');
        $token = $table->token();

        $this->get(route('customer.register', $token))
            ->assertOk()
            ->assertSee('minlength="8"', false)
            ->assertSee('En az 8 karakter');

        $this->post(route('customer.register.store', $token), [
            'name' => 'Kısa Şifre',
            'email' => 'kisa@example.com',
            'password' => 'abc1234',
            'password_confirmation' => 'abc1234',
        ])->assertSessionHasErrors('password');

        $this->post(route('customer.register.store', $token), [
            'name' => 'Sekiz Karakter',
            'email' => 'sekiz@example.com',
            'password' => 'Coffee88',
            'password_confirmation' => 'Coffee88',
        ])->assertRedirect(route('customer.menu', $token));

        $this->assertDatabaseHas('users', ['email' => 'sekiz@example.com', 'role' => User::ROLE_CUSTOMER]);
    }

    public function test_customer_account_shows_cafe_scoped_points_promotions_savings_and_order_history(): void
    {
        [$cafe, $branch, $table] = $this->cafeWithTable('Hesap Kafe', 'hesap-kafe');
        $cafe->forceFill(['loyalty_enabled' => true, 'points_per_100' => 2])->save();
        $customer = User::factory()->create([
            'name' => 'Hesap Müşterisi',
            'email' => 'hesap@example.com',
            'role' => User::ROLE_CUSTOMER,
            'cafe_id' => null,
        ]);
        LoyaltyAccount::query()->create([
            'cafe_id' => $cafe->id,
            'user_id' => $customer->id,
            'points' => 75,
            'completed_order_count' => 3,
        ]);
        $cafe->loyaltyCampaigns()->create([
            'name' => '50 puanla 20 TL',
            'type' => 'points',
            'threshold' => 50,
            'points_required' => 50,
            'reward_type' => 'fixed',
            'reward_value' => '20.00',
            'minimum_order_total' => '0.00',
            'is_active' => true,
        ]);
        $cafe->loyaltyCampaigns()->create([
            'name' => '100 puanla yüzde 10',
            'type' => 'points',
            'threshold' => 100,
            'points_required' => 100,
            'reward_type' => 'percent',
            'reward_value' => '10.00',
            'minimum_order_total' => '0.00',
            'is_active' => true,
        ]);
        $cafe->announcements()->create([
            'type' => 'announcement',
            'title' => 'Öğrenci kahvesi kampanyası',
            'body' => 'Kampanya bu kafede aktif.',
            'is_active' => true,
        ]);

        $this->actingAs($customer);
        $paidOrder = $this->placeOrder($cafe, $branch, $table, 'Ödenmiş kahve', '150.00', 1);
        $paidOrder->forceFill(['discount_total' => '25.00'])->save();
        LoyaltyTransaction::query()->create([
            'cafe_id' => $cafe->id,
            'user_id' => $customer->id,
            'type' => 'earned',
            'points_delta' => 120,
            'orders_delta' => 1,
            'discount_amount' => '0.00',
            'description' => 'Geçmişte kazanılan puanlar',
        ]);

        [$otherCafe, $otherBranch, $otherTable] = $this->cafeWithTable('Diğer Kafe', 'diger-hesap-kafe');
        $otherCafeOrder = $this->placeOrder($otherCafe, $otherBranch, $otherTable, 'Başka kafe siparişi', '50.00', 1);
        $cancelledOrder = $this->placeOrder($cafe, $branch, $table, 'İptal edilen kahve', '80.00', 1);
        $cancelledOrder->forceFill([
            'status' => Order::STATUS_CANCELLED,
            'discount_total' => '99.00',
            'paid_at' => null,
        ])->save();

        $this->get(route('customer.menu', $table->token()))
            ->assertOk()
            ->assertSee(route('customer.account', $table->token()), false)
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');

        $this->get(route('customer.order.track', ['token' => $table->token(), 'orderNumber' => $paidOrder->order_number]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');

        $this->get(route('customer.bill', $table->token()))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');

        $this->getJson(route('customer.status', $table->token()))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private');

        $this->withSession(['status' => null])
            ->get(route('customer.account', $table->token()))
            ->assertOk()
            ->assertSee('Hesap Müşterisi')
            ->assertSee('75')
            ->assertSee('120')
            ->assertSee(Money::format(2500))
            ->assertSee('50 puanla 20 TL')
            ->assertSee('100 puanla yüzde 10')
            ->assertSee('Öğrenci kahvesi kampanyası')
            ->assertSee($paidOrder->order_number)
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-store, private')
            ->assertDontSee(Money::format(9900))
            ->assertDontSee($otherCafeOrder->order_number);
    }

    public function test_customer_menu_hides_account_link_when_cafe_registration_is_disabled(): void
    {
        [$cafe, , $table] = $this->cafeWithTable('Misafir Kafe', 'misafir-kafe');
        $cafe->forceFill(['registration_policy' => Cafe::POLICY_GUEST])->save();
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'cafe_id' => null,
        ]);

        $this->actingAs($customer)
            ->get(route('customer.menu', $table->token()))
            ->assertOk()
            ->assertDontSee(route('customer.account', $table->token()), false);
    }

    public function test_customer_account_redirects_non_customers_to_the_qr_login(): void
    {
        [, , $table] = $this->cafeWithTable('Hesap Girişi', 'hesap-girisi');
        $token = $table->token();

        $this->get(route('customer.account', $token))
            ->assertRedirect(route('customer.login', $token));

        $waiter = User::factory()->create(['role' => User::ROLE_WAITER]);
        $this->actingAs($waiter)
            ->get(route('customer.account', $token))
            ->assertRedirect(route('customer.login', $token));
    }

    public function test_required_registration_cannot_be_bypassed_with_direct_customer_endpoints(): void
    {
        [$cafe, , $table] = $this->cafeWithTable('Zorunlu Kafe', 'zorunlu-kafe');
        $cafe->forceFill(['registration_policy' => Cafe::POLICY_REQUIRED])->save();
        $token = $table->token();

        $this->get(route('customer.bill', $token))
            ->assertRedirect(route('customer.login', $token));
        $this->get(route('customer.order.track', ['token' => $token, 'orderNumber' => 'ANY']))
            ->assertRedirect(route('customer.login', $token));
        $this->getJson(route('customer.status', $token))->assertUnauthorized();
        $this->postJson(route('customer.call', $token), ['type' => 'waiter'])->assertUnauthorized();
        $this->postJson(route('customer.coupon.check', $token), [
            'code' => 'TEST',
            'subtotal_cents' => 1000,
        ])->assertUnauthorized();
    }

    public function test_deactivated_customer_cannot_use_required_cafe_menu_or_order_endpoints(): void
    {
        [$cafe, , $table] = $this->cafeWithTable('Hesabı Kapalı Kafe', 'hesabi-kapali-kafe');
        $cafe->forceFill(['registration_policy' => Cafe::POLICY_REQUIRED])->save();
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'cafe_id' => null,
            'is_active' => false,
        ]);

        $this->actingAs($customer)
            ->get(route('customer.menu', $table->token()))
            ->assertRedirect(route('customer.login', $table->token()));
        $this->actingAs($customer)
            ->get(route('customer.login', $table->token()))
            ->assertOk()
            ->assertSee(route('logout'), false);
        $this->actingAs($customer)
            ->get(route('customer.bill', $table->token()))
            ->assertRedirect(route('customer.login', $table->token()));
        $this->actingAs($customer)
            ->getJson(route('customer.status', $table->token()))
            ->assertUnauthorized();
        $this->actingAs($customer)
            ->postJson(route('customer.call', $table->token()), ['type' => 'waiter'])
            ->assertUnauthorized();
    }

    public function test_customer_menu_footer_links_to_the_skykite_site(): void
    {
        [, , $table] = $this->cafeWithTable('SkyKite Kafe', 'skykite-kafe');

        $this->get(route('customer.menu', $table->token()))
            ->assertOk()
            ->assertSee('https://skykite.com.tr/?utm_source=masa_qr&amp;utm_medium=menu&amp;utm_campaign=cross_promotion', false)
            ->assertSee(__('customer.brand_promo_eyebrow'))
            ->assertSee(__('customer.brand_promo_title'))
            ->assertSee(__('customer.brand_promo_description'))
            ->assertSee(__('customer.brand_promo_cta'))
            ->assertSee(__('customer.previous_featured'))
            ->assertSee(__('customer.next_featured'))
            ->assertSee('role="region" aria-label="'.__('customer.popular').'"', false);
    }

    public function test_an_existing_non_customer_session_can_continue_through_qr_customer_login(): void
    {
        [$cafe, , $table] = $this->cafeWithTable('Hesap Gerekli Kafe', 'hesap-gerekli-kafe');
        $cafe->forceFill(['registration_policy' => Cafe::POLICY_REQUIRED])->save();
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id]);
        $customer = User::factory()->create([
            'email' => 'qr-musteri@example.com',
            'password' => 'Customer!2026',
            'role' => User::ROLE_CUSTOMER,
            'cafe_id' => null,
        ]);

        $this->actingAs($waiter)->get(route('customer.menu', $table->token()))
            ->assertRedirect(route('customer.login', $table->token()));
        $this->actingAs($waiter)->get(route('customer.login', $table->token()))
            ->assertOk()
            ->assertSee(route('logout'), false);
        $this->actingAs($waiter)->post(route('customer.login.store', $table->token()), [
            'email' => $customer->email,
            'password' => 'Customer!2026',
        ])->assertRedirect(route('customer.menu', $table->token()));

        $this->assertAuthenticatedAs($customer);
    }

    public function test_optional_loyalty_shows_the_signup_teaser_and_cafe_announcements_are_branch_scoped(): void
    {
        [$cafe, $branch, $table] = $this->cafeWithTable('Duyuru Kafe', 'duyuru-kafe');
        $cafe->forceFill([
            'registration_policy' => Cafe::POLICY_OPTIONAL,
            'loyalty_enabled' => true,
        ])->save();
        $otherBranch = $cafe->branches()->create(['name' => 'İkinci Şube', 'slug' => 'ikinci', 'is_active' => true]);

        $cafe->announcements()->create([
            'type' => 'announcement',
            'title' => 'Tüm şubelerde mutlu saat',
            'body' => 'Her gün 16.00-18.00 arası indirim.',
            'is_active' => true,
        ]);
        $branch->announcements()->create([
            'type' => 'event',
            'title' => 'Bu şubede canlı müzik',
            'body' => 'Cumartesi akşamı sahnedeyiz.',
            'is_active' => true,
        ]);
        $otherBranch->announcements()->create([
            'type' => 'announcement',
            'title' => 'Başka şubeye özel duyuru',
            'body' => 'Bu duyuru bu masada görünmemeli.',
            'is_active' => true,
        ]);
        $cafe->announcements()->create([
            'type' => 'announcement',
            'title' => 'Süresi geçmiş duyuru',
            'body' => 'Görünmemeli.',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $this->get(route('customer.menu', $table->token()))
            ->assertOk()
            ->assertSee(__('customer.loyalty_teaser_body'))
            ->assertSee('Tüm şubelerde mutlu saat')
            ->assertSee('Bu şubede canlı müzik')
            ->assertDontSee('Başka şubeye özel duyuru')
            ->assertDontSee('Süresi geçmiş duyuru');
    }

    public function test_kitchen_login_redirects_to_the_kitchen_screen(): void
    {
        [$cafe, $branch] = $this->cafeWithTable('Mutfak Kafe', 'mutfak-kafe');
        $kitchen = User::factory()->create([
            'name' => 'Mutfak Şefi',
            'email' => 'mutfak@example.com',
            'password' => 'Kitchen!2026',
            'role' => User::ROLE_KITCHEN,
            'cafe_id' => $cafe->id,
        ]);
        $branch->staff()->attach($kitchen->id);

        $this->post(route('login.store'), [
            'email' => 'mutfak@example.com',
            'password' => 'Kitchen!2026',
        ])->assertRedirect(route('staff.kitchen'));

        $this->assertAuthenticatedAs($kitchen);
    }

    /** @return array{Cafe, Branch, DiningTable} */
    private function cafeWithTable(string $name, string $slug): array
    {
        $cafe = Cafe::query()->create([
            'name' => $name,
            'slug' => $slug,
            'status' => Cafe::STATUS_ACTIVE,
            'registration_policy' => Cafe::POLICY_OPTIONAL,
        ]);
        $branch = $cafe->branches()->create([
            'name' => 'Merkez',
            'slug' => 'merkez',
            'is_active' => true,
        ]);
        $table = DiningTable::createWithToken([
            'branch_id' => $branch->id,
            'name' => 'Masa 1',
            'is_active' => true,
        ]);

        return [$cafe, $branch, $table];
    }

    private function placeOrder(Cafe $cafe, Branch $branch, DiningTable $table, string $productName, string $price, int $quantity): Order
    {
        $category = $cafe->categories()->create([
            'name' => $productName.' kategorisi',
            'is_active' => true,
        ]);
        $product = $cafe->products()->create([
            'category_id' => $category->id,
            'name' => $productName,
            'price' => $price,
            'is_available' => true,
        ]);

        TableSession::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);

        $this->post(route('customer.orders.store', $table->token()), [
            'lines' => [
                ['product_id' => $product->id, 'quantity' => $quantity],
            ],
        ])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();
        $order->forceFill(['status' => Order::STATUS_PAID, 'paid_at' => now()])->save();

        return $order;
    }
}
