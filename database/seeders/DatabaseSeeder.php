<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\Cafe;
use App\Models\Coupon;
use App\Models\DiningTable;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyCampaign;
use App\Models\MenuCategory;
use App\Models\MenuOption;
use App\Models\MenuOptionGroup;
use App\Models\MenuProduct;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $cafe = $this->seedCafe();
        $branch = $this->seedBranch($cafe);
        $users = $this->seedUsers($cafe, $branch);
        $products = $this->seedMenu($cafe, $branch);
        $tables = $this->seedTables($branch);
        $this->seedCouponsAndLoyalty($cafe, $users['customer']);
        $this->seedAnnouncements($cafe, $branch, $users['owner']);
        $this->seedHistory($cafe, $branch, $users['waiter'], $tables, $products);
        $this->seedLiveTables($cafe, $branch, $users, $tables, $products);

        ActivityLog::query()->create([
            'cafe_id' => $cafe->id,
            'branch_id' => $branch->id,
            'user_id' => $users['owner']->id,
            'action' => 'demo.seeded',
            'metadata' => ['source' => 'DatabaseSeeder'],
        ]);
    }

    private function seedCafe(): Cafe
    {
        return Cafe::query()->updateOrCreate(
            ['slug' => 'cafe-34'],
            [
                'name' => 'Cafe 34',
                'status' => Cafe::STATUS_ACTIVE,
                'registration_policy' => Cafe::POLICY_OPTIONAL,
                'loyalty_enabled' => true,
                'points_per_100' => 1,
                'brand_name' => 'Cafe 34',
                'description' => 'Girne sahilinde üçüncü nesil kahve, ev yapımı tatlılar ve sakin bir sabah molası.',
                'phone' => '+90 392 444 34 34',
                'address' => 'Kordonboyu, Girne, KKTC',
                'instagram' => 'cafe34',
                'logo_path' => 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=240&q=80',
                'cover_path' => 'https://images.unsplash.com/photo-1554118811-1e0d58224f24?auto=format&fit=crop&w=1800&q=80',
            ],
        );
    }

    private function seedBranch(Cafe $cafe): Branch
    {
        return Branch::query()->updateOrCreate(
            ['cafe_id' => $cafe->id, 'slug' => 'girne-merkez'],
            [
                'name' => 'Girne / Merkez',
                'address' => 'Kordonboyu Cd. No 34, Girne',
                'phone' => '+90 392 444 34 34',
                'uses_shared_menu' => true,
                'is_active' => true,
            ],
        );
    }

    /**
     * @return array{owner: User, manager: User, waiter: User, kitchen: User, customer: User}
     */
    private function seedUsers(Cafe $cafe, Branch $branch): array
    {
        $owner = $this->seedDemoUser('cafe34@mail.com', 'Deniz Kaya', User::ROLE_CAFE_OWNER, $cafe->id);
        $manager = $this->seedDemoUser('yonetici@cafe34.com', 'Ece Yıldız', User::ROLE_CAFE_MANAGER, $cafe->id);
        $waiter = $this->seedDemoUser('mehmet@mail.com', 'Mehmet Aksoy', User::ROLE_WAITER, $cafe->id);
        $kitchen = $this->seedDemoUser('mutfak@cafe34.com', 'Barış Demir', User::ROLE_KITCHEN, $cafe->id);
        $customer = $this->seedDemoUser('musteri@mail.com', 'Selin Arslan', User::ROLE_CUSTOMER, null);

        $branch->staff()->syncWithoutDetaching([$owner->id, $manager->id, $waiter->id, $kitchen->id]);

        return compact('owner', 'manager', 'waiter', 'kitchen', 'customer');
    }

    private function seedDemoUser(string $email, string $name, string $role, ?int $cafeId): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Str::random(64),
                'role' => $role,
                'cafe_id' => $cafeId,
                'is_active' => true,
            ],
        );
    }

    /**
     * @return Collection<string, MenuProduct>
     */
    private function seedMenu(Cafe $cafe, Branch $branch): Collection
    {
        $menu = [
            'Sıcak Kahveler' => [
                'description' => 'Üçüncü nesil çekirdekler, günlük demleme.',
                'products' => [
                    ['Espresso', 'Kısa, yoğun ve kakao notalı. 30 ml.', '75.00', 2, true, 'https://images.unsplash.com/photo-1510707577719-ae7c14805e3a?auto=format&fit=crop&w=900&q=80'],
                    ['Flat White', 'Double shot espresso, ipeksi mikro köpük.', '110.00', 4, true, 'https://images.unsplash.com/photo-1572442388796-11668a67e53d?auto=format&fit=crop&w=900&q=80'],
                    ['Filtre Kahve', 'Etiyopya ve Brezilya harmanı, V60.', '95.00', 5, false, 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=900&q=80'],
                    ['Latte', 'Espresso ve buharlanmış süt.', '105.00', 4, false, 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?auto=format&fit=crop&w=900&q=80'],
                    ['Türk Kahvesi', 'Bakır cezvede, orta şekerli.', '70.00', 6, false, 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?auto=format&fit=crop&w=900&q=80'],
                ],
            ],
            'Soğuk İçecekler' => [
                'description' => 'Buz gibi, ferahlatıcı.',
                'products' => [
                    ['Cold Brew', '18 saat soğuk demleme, portakal kabuğu.', '115.00', 2, true, 'https://images.unsplash.com/photo-1517701550927-30cf4ba1dba5?auto=format&fit=crop&w=900&q=80'],
                    ['Iced Latte', 'Buz, espresso ve süt.', '120.00', 4, false, 'https://images.unsplash.com/photo-1525385133512-2f3bdd039054?auto=format&fit=crop&w=900&q=80'],
                    ['Ev Yapımı Limonata', 'Taze limon, nane ve az şeker.', '85.00', 3, true, 'https://images.unsplash.com/photo-1513558161293-cdaf765ed2fd?auto=format&fit=crop&w=900&q=80'],
                    ['Matcha Latte', 'Tören sınıfı matcha, süt.', '130.00', 5, false, 'https://images.unsplash.com/photo-1515823064-d6e0c04616a7?auto=format&fit=crop&w=900&q=80'],
                ],
            ],
            'Tatlılar' => [
                'description' => 'Her sabah taze pişirilir.',
                'products' => [
                    ['San Sebastian Cheesecake', 'Yanık yüzey, akışkan merkez.', '150.00', 2, true, 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?auto=format&fit=crop&w=900&q=80'],
                    ['Brownie', '%70 bitter çikolata ve ceviz.', '120.00', 2, false, 'https://images.unsplash.com/photo-1606313564200-e75d5e30476c?auto=format&fit=crop&w=900&q=80'],
                    ['Cheesecake', 'Frambuazlı klasik cheesecake.', '140.00', 2, false, 'https://images.unsplash.com/photo-1533134242443-d4fd215305ad?auto=format&fit=crop&w=900&q=80'],
                ],
            ],
            'Atıştırmalıklar' => [
                'description' => 'Hafif ve doyurucu.',
                'products' => [
                    ['Avokado Tost', 'Ekşi mayalı ekmek, avokado, poşe yumurta.', '165.00', 9, true, 'https://images.unsplash.com/photo-1541519227354-08fa5d50c44d?auto=format&fit=crop&w=900&q=80'],
                    ['Tavuk Wrap', 'Izgara tavuk, yeşillik, yoğurt sos.', '185.00', 12, false, 'https://images.unsplash.com/photo-1626700051175-6818013e1d4f?auto=format&fit=crop&w=900&q=80'],
                    ['Kruvasan', 'Tereyağlı, 27 kat.', '95.00', 2, false, 'https://images.unsplash.com/photo-1555507036-ab1f4038808a?auto=format&fit=crop&w=900&q=80'],
                ],
            ],
        ];

        $products = collect();
        $sort = 0;

        foreach ($menu as $categoryName => $payload) {
            $category = MenuCategory::query()->updateOrCreate(
                [
                    'cafe_id' => $cafe->id,
                    'branch_id' => null,
                    'name' => $categoryName,
                ],
                [
                    'description' => $payload['description'],
                    'sort_order' => $sort++,
                    'is_active' => true,
                ],
            );

            $productSort = 0;

            foreach ($payload['products'] as [$name, $description, $price, $prep, $featured, $image]) {
                $product = MenuProduct::query()->updateOrCreate(
                    [
                        'cafe_id' => $cafe->id,
                        'branch_id' => null,
                        'name' => $name,
                    ],
                    [
                        'category_id' => $category->id,
                        'description' => $description,
                        'image_path' => $image,
                        'price' => $price,
                        'prep_minutes' => $prep,
                        'is_available' => true,
                        'is_featured' => $featured,
                        'sort_order' => $productSort++,
                    ],
                );

                $products->put($name, $product);
            }
        }

        $this->seedOptions($products);

        $suggestionMap = [
            'Espresso' => ['Brownie', 'San Sebastian Cheesecake'],
            'Flat White' => ['Kruvasan', 'Cheesecake'],
            'Filtre Kahve' => ['Kruvasan', 'Avokado Tost'],
            'Latte' => ['Brownie', 'Kruvasan'],
            'Türk Kahvesi' => ['Cheesecake', 'Ev Yapımı Limonata'],
            'Cold Brew' => ['Avokado Tost', 'Brownie'],
            'Iced Latte' => ['Tavuk Wrap', 'Cheesecake'],
            'Ev Yapımı Limonata' => ['Tavuk Wrap', 'Avokado Tost'],
            'Matcha Latte' => ['Cheesecake', 'Kruvasan'],
            'San Sebastian Cheesecake' => ['Espresso', 'Filtre Kahve'],
            'Brownie' => ['Flat White', 'Cold Brew'],
            'Cheesecake' => ['Latte', 'Türk Kahvesi'],
            'Avokado Tost' => ['Cold Brew', 'Ev Yapımı Limonata'],
            'Tavuk Wrap' => ['Ev Yapımı Limonata', 'Iced Latte'],
            'Kruvasan' => ['Flat White', 'Filtre Kahve'],
        ];

        foreach ($suggestionMap as $name => $suggestions) {
            $products->get($name)?->forceFill([
                'suggestions' => collect($suggestions)
                    ->map(fn (string $suggestion): ?int => $products->get($suggestion)?->id)
                    ->filter()
                    ->values()
                    ->all(),
            ])->save();
        }

        return $products;
    }

    /**
     * @param  Collection<string, MenuProduct>  $products
     */
    private function seedOptions(Collection $products): void
    {
        $milk = $products->get('Flat White');

        if ($milk !== null) {
            $group = MenuOptionGroup::query()->updateOrCreate(
                [
                    'menu_product_id' => $milk->id,
                    'name' => 'Süt seçimi',
                ],
                [
                    'selection_mode' => 'single',
                    'min_selections' => 1,
                    'max_selections' => 1,
                    'sort_order' => 0,
                    'is_active' => true,
                ],
            );

            foreach ([['Tam yağlı', '0.00'], ['Laktozsuz', '10.00'], ['Badem sütü', '20.00']] as $index => [$name, $delta]) {
                MenuOption::query()->updateOrCreate(
                    [
                        'menu_option_group_id' => $group->id,
                        'name' => $name,
                    ],
                    [
                        'price_delta' => $delta,
                        'is_available' => true,
                        'sort_order' => $index,
                    ],
                );
            }
        }

        $espresso = $products->get('Espresso');

        if ($espresso !== null) {
            $group = MenuOptionGroup::query()->updateOrCreate(
                [
                    'menu_product_id' => $espresso->id,
                    'name' => 'Ekstralar',
                ],
                [
                    'selection_mode' => 'multiple',
                    'min_selections' => 0,
                    'max_selections' => 3,
                    'sort_order' => 0,
                    'is_active' => true,
                ],
            );

            foreach ([['Ekstra shot', '25.00'], ['Vanilya şurubu', '15.00'], ['Karamel', '15.00']] as $index => [$name, $delta]) {
                MenuOption::query()->updateOrCreate(
                    [
                        'menu_option_group_id' => $group->id,
                        'name' => $name,
                    ],
                    [
                        'price_delta' => $delta,
                        'is_available' => true,
                        'sort_order' => $index,
                    ],
                );
            }
        }

        $iced = $products->get('Iced Latte');

        if ($iced !== null) {
            $group = MenuOptionGroup::query()->updateOrCreate(
                [
                    'menu_product_id' => $iced->id,
                    'name' => 'Buz',
                ],
                [
                    'selection_mode' => 'single',
                    'min_selections' => 1,
                    'max_selections' => 1,
                    'sort_order' => 0,
                    'is_active' => true,
                ],
            );

            foreach ([['Az buz', '0.00'], ['Normal', '0.00'], ['Buzsuz', '0.00']] as $index => [$name, $delta]) {
                MenuOption::query()->updateOrCreate(
                    [
                        'menu_option_group_id' => $group->id,
                        'name' => $name,
                    ],
                    [
                        'price_delta' => $delta,
                        'is_available' => true,
                        'sort_order' => $index,
                    ],
                );
            }
        }
    }

    /**
     * @return Collection<string, DiningTable>
     */
    private function seedTables(Branch $branch): Collection
    {
        $tables = collect();

        foreach (range(1, 8) as $number) {
            $name = 'Masa '.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $table = DiningTable::query()->where('branch_id', $branch->id)->where('name', $name)->first();

            if ($table === null) {
                $table = DiningTable::createWithToken([
                    'branch_id' => $branch->id,
                    'name' => $name,
                    'is_active' => true,
                ]);
            }

            $tables->put($name, $table);
        }

        return $tables;
    }

    private function seedCouponsAndLoyalty(Cafe $cafe, User $customer): void
    {
        Coupon::query()->updateOrCreate(
            ['cafe_id' => $cafe->id, 'code' => 'HOSGELDIN10'],
            [
                'type' => Coupon::TYPE_PERCENT,
                'value' => '10.00',
                'min_subtotal' => '150.00',
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addMonths(6),
                'usage_limit' => 500,
                'used_count' => 0,
                'is_active' => true,
            ],
        );

        Coupon::query()->updateOrCreate(
            ['cafe_id' => $cafe->id, 'code' => 'KAHVE50'],
            [
                'type' => Coupon::TYPE_FIXED,
                'value' => '50.00',
                'min_subtotal' => '250.00',
                'starts_at' => now()->subWeek(),
                'ends_at' => now()->addMonths(3),
                'usage_limit' => 200,
                'used_count' => 0,
                'is_active' => true,
            ],
        );

        LoyaltyCampaign::query()->updateOrCreate(
            ['cafe_id' => $cafe->id, 'name' => '100 puana 30 ₺ indirim'],
            [
                'type' => LoyaltyCampaign::TYPE_POINTS,
                'threshold' => 100,
                'points_required' => 100,
                'reward_type' => LoyaltyCampaign::REWARD_FIXED,
                'reward_value' => '30.00',
                'minimum_order_total' => '100.00',
                'is_active' => true,
                'starts_at' => now()->subMonth(),
                'ends_at' => now()->addMonths(6),
            ],
        );

        LoyaltyAccount::query()->updateOrCreate(
            ['cafe_id' => $cafe->id, 'user_id' => $customer->id],
            ['points' => 140, 'completed_order_count' => 7],
        );
    }

    private function seedAnnouncements(Cafe $cafe, Branch $branch, User $owner): void
    {
        Announcement::query()->updateOrCreate(
            ['cafe_id' => $cafe->id, 'branch_id' => null, 'title' => 'Hafta sonu canlı müzik'],
            [
                'type' => Announcement::TYPE_EVENT,
                'body' => 'Cuma ve cumartesi akşamları saat 21.00’de sahnemizde canlı müzik var. Masanızı QR üzerinden ayırtabilirsiniz.',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addMonths(2),
                'is_active' => true,
                'created_by' => $owner->id,
            ],
        );

        Announcement::query()->updateOrCreate(
            ['cafe_id' => $cafe->id, 'branch_id' => $branch->id, 'title' => 'Günün tatlısı: San Sebastian'],
            [
                'type' => Announcement::TYPE_ANNOUNCEMENT,
                'body' => 'Bugün tüm şubelerimizde San Sebastian cheesecake yanında filtre kahve ikramımız var.',
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addWeek(),
                'is_active' => true,
                'created_by' => $owner->id,
            ],
        );
    }

    /**
     * @param  Collection<string, DiningTable>  $tables
     * @param  Collection<string, MenuProduct>  $products
     */
    private function seedHistory(Cafe $cafe, Branch $branch, User $waiter, Collection $tables, Collection $products): void
    {
        if (Order::query()->where('cafe_id', $cafe->id)->where('order_number', 'like', 'DEMO-%')->exists()) {
            return;
        }

        $productNames = ['Espresso', 'Flat White', 'Filtre Kahve', 'Latte', 'Cold Brew', 'Ev Yapımı Limonata', 'San Sebastian Cheesecake', 'Brownie', 'Avokado Tost', 'Tavuk Wrap', 'Kruvasan'];
        $tableNames = $tables->keys()->all();

        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $ordersToday = $daysAgo === 0 ? 4 : random_int(2, 4);

            for ($index = 1; $index <= $ordersToday; $index++) {
                $openedAt = Carbon::today()->subDays($daysAgo)->setTime(10 + $index, 5);
                $closedAt = $openedAt->copy()->addMinutes(random_int(30, 75));

                $session = TableSession::query()->create([
                    'branch_id' => $branch->id,
                    'dining_table_id' => $tables[$tableNames[($daysAgo + $index) % count($tableNames)]]->id,
                    'status' => TableSession::STATUS_CLOSED,
                    'opened_by' => $waiter->id,
                    'closed_by' => $waiter->id,
                    'opened_at' => $openedAt,
                    'closed_at' => $closedAt,
                    'closing_note' => 'Demo geçmişi',
                ]);

                $picked = collect($productNames)->shuffle()->take(random_int(1, 3));
                $subtotalCents = 0;
                $lines = [];

                foreach ($picked as $name) {
                    $product = $products->get($name);
                    $quantity = random_int(1, 2);
                    $unitCents = (int) round(((float) $product->price) * 100);
                    $subtotalCents += $unitCents * $quantity;
                    $lines[] = [$product, $quantity, $unitCents];
                }

                $order = Order::query()->create([
                    'cafe_id' => $cafe->id,
                    'branch_id' => $branch->id,
                    'table_session_id' => $session->id,
                    'created_by' => $waiter->id,
                    'order_number' => 'DEMO-'.$openedAt->format('Ymd').'-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                    'source' => 'qr',
                    'status' => Order::STATUS_PAID,
                    'subtotal' => $this->money($subtotalCents),
                    'discount_total' => '0.00',
                    'total' => $this->money($subtotalCents),
                    'accepted_at' => $openedAt->copy()->addMinutes(3),
                    'ready_at' => $openedAt->copy()->addMinutes(12),
                    'served_at' => $openedAt->copy()->addMinutes(18),
                    'paid_at' => $closedAt,
                ]);

                $order->forceFill([
                    'created_at' => $openedAt,
                    'updated_at' => $closedAt,
                ])->save();

                foreach ($lines as [$product, $quantity, $unitCents]) {
                    $order->items()->create([
                        'menu_product_id' => $product->id,
                        'name_snapshot' => $product->name,
                        'unit_price' => $this->money($unitCents),
                        'quantity' => $quantity,
                        'line_total' => $this->money($unitCents * $quantity),
                    ]);
                }

                Payment::query()->create([
                    'table_session_id' => $session->id,
                    'recorded_by' => $waiter->id,
                    'method' => $index % 3 === 0 ? Payment::METHOD_CASH : Payment::METHOD_CARD,
                    'amount' => $this->money($subtotalCents),
                    'recorded_at' => $closedAt,
                ]);

                $session->forceFill([
                    'created_at' => $openedAt,
                    'updated_at' => $closedAt,
                ])->save();
            }
        }
    }

    /**
     * @param  array{owner: User, manager: User, waiter: User, kitchen: User, customer: User}  $users
     * @param  Collection<string, DiningTable>  $tables
     * @param  Collection<string, MenuProduct>  $products
     */
    private function seedLiveTables(Cafe $cafe, Branch $branch, array $users, Collection $tables, Collection $products): void
    {
        $live = [
            ['Masa 02', Order::STATUS_PENDING, 2, 'Az şekerli olsun lütfen.', ['Flat White', 'Brownie']],
            ['Masa 05', Order::STATUS_ACCEPTED, 9, null, ['Cold Brew', 'Avokado Tost']],
            ['Masa 07', Order::STATUS_READY, 14, 'Çatal-bıçak istiyorum.', ['Türk Kahvesi', 'San Sebastian Cheesecake']],
        ];

        foreach ($live as [$tableName, $status, $minutesAgo, $note, $productNames]) {
            $table = $tables->get($tableName);

            if ($table === null || $table->openSession() !== null) {
                continue;
            }

            $openedAt = now()->subMinutes($minutesAgo + 8);
            $session = TableSession::query()->create([
                'branch_id' => $branch->id,
                'dining_table_id' => $table->id,
                'status' => TableSession::STATUS_OPEN,
                'opened_by' => $users['waiter']->id,
                'opened_at' => $openedAt,
            ]);

            $subtotalCents = 0;
            $lines = [];

            foreach ($productNames as $name) {
                $product = $products->get($name);
                $quantity = 1;
                $unitCents = (int) round(((float) $product->price) * 100);
                $subtotalCents += $unitCents;
                $lines[] = [$product, $unitCents];
            }

            $order = Order::query()->create([
                'cafe_id' => $cafe->id,
                'branch_id' => $branch->id,
                'table_session_id' => $session->id,
                'customer_id' => $users['customer']->id,
                'order_number' => 'DEMO-CANLI-'.str_pad((string) $table->id, 2, '0', STR_PAD_LEFT),
                'source' => 'qr',
                'status' => $status,
                'subtotal' => $this->money($subtotalCents),
                'discount_total' => '0.00',
                'total' => $this->money($subtotalCents),
                'customer_note' => $note,
                'accepted_at' => $status !== Order::STATUS_PENDING ? now()->subMinutes($minutesAgo) : null,
                'ready_at' => $status === Order::STATUS_READY ? now()->subMinutes(2) : null,
            ]);

            $order->forceFill([
                'created_at' => now()->subMinutes($minutesAgo),
                'updated_at' => now()->subMinutes($minutesAgo),
            ])->save();

            foreach ($lines as [$product, $unitCents]) {
                $order->items()->create([
                    'menu_product_id' => $product->id,
                    'name_snapshot' => $product->name,
                    'unit_price' => $this->money($unitCents),
                    'quantity' => 1,
                    'line_total' => $this->money($unitCents),
                ]);
            }

            $session->forceFill(['created_at' => $openedAt])->save();
        }

        $serviceTable = $tables->get('Masa 03');

        if ($serviceTable?->openSession() === null) {
            $session = TableSession::query()->create([
                'branch_id' => $branch->id,
                'dining_table_id' => $serviceTable->id,
                'status' => TableSession::STATUS_OPEN,
                'opened_by' => $users['waiter']->id,
                'opened_at' => now()->subMinutes(20),
            ]);

            ServiceRequest::query()->create([
                'branch_id' => $branch->id,
                'dining_table_id' => $serviceTable->id,
                'table_session_id' => $session->id,
                'type' => ServiceRequest::TYPE_BILL,
                'status' => ServiceRequest::STATUS_OPEN,
                'note' => 'Hesap istendi.',
            ]);
        }
    }

    private function money(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
