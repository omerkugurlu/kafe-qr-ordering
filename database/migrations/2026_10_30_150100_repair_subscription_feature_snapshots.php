<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subscription_plans') && ! Schema::hasColumn('subscription_plans', 'features_json')) {
            if (Schema::hasColumn('subscription_plans', 'features')) {
                Schema::table('subscription_plans', function (Blueprint $table): void {
                    $table->renameColumn('features', 'features_json');
                });
            } else {
                Schema::table('subscription_plans', function (Blueprint $table): void {
                    $table->json('features_json')->nullable();
                });
            }
        }

        if (Schema::hasTable('cafe_subscriptions') && ! Schema::hasColumn('cafe_subscriptions', 'features_snapshot_json')) {
            if (Schema::hasColumn('cafe_subscriptions', 'features_snapshot')) {
                Schema::table('cafe_subscriptions', function (Blueprint $table): void {
                    $table->renameColumn('features_snapshot', 'features_snapshot_json');
                });
            } else {
                Schema::table('cafe_subscriptions', function (Blueprint $table): void {
                    $table->json('features_snapshot_json')->nullable();
                });
            }
        }

        if (Schema::hasTable('cafe_subscription_requests') && ! Schema::hasColumn('cafe_subscription_requests', 'features_snapshot_json')) {
            if (Schema::hasColumn('cafe_subscription_requests', 'features_snapshot')) {
                Schema::table('cafe_subscription_requests', function (Blueprint $table): void {
                    $table->renameColumn('features_snapshot', 'features_snapshot_json');
                });
            } else {
                Schema::table('cafe_subscription_requests', function (Blueprint $table): void {
                    $table->json('features_snapshot_json')->nullable();
                });
            }
        }

        if (Schema::hasTable('subscription_plans') && Schema::hasColumn('subscription_plans', 'features_json') && DB::table('subscription_plans')->count() === 0) {
            $now = now();
            $fullFeatures = ['customer_accounts' => true, 'coupons' => true, 'loyalty' => true, 'reports' => true, 'announcements' => true];
            $entryFeatures = array_fill_keys(array_keys($fullFeatures), false);
            DB::table('subscription_plans')->insert([
                ['code' => 'giris', 'name' => 'Giriş', 'annual_price_cents' => 1000000, 'max_tables' => 10, 'max_branches' => 2, 'features_json' => json_encode($entryFeatures), 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'orta', 'name' => 'Orta', 'annual_price_cents' => 2000000, 'max_tables' => 20, 'max_branches' => null, 'features_json' => json_encode($fullFeatures), 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'yuksek', 'name' => 'En Yüksek', 'annual_price_cents' => 5000000, 'max_tables' => 50, 'max_branches' => null, 'features_json' => json_encode($fullFeatures), 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cafe_subscription_requests') && Schema::hasColumn('cafe_subscription_requests', 'features_snapshot_json')) {
            Schema::table('cafe_subscription_requests', function (Blueprint $table): void {
                $table->renameColumn('features_snapshot_json', 'features_snapshot');
            });
        }

        if (Schema::hasTable('cafe_subscriptions') && Schema::hasColumn('cafe_subscriptions', 'features_snapshot_json')) {
            Schema::table('cafe_subscriptions', function (Blueprint $table): void {
                $table->renameColumn('features_snapshot_json', 'features_snapshot');
            });
        }

        if (Schema::hasTable('subscription_plans') && Schema::hasColumn('subscription_plans', 'features_json')) {
            Schema::table('subscription_plans', function (Blueprint $table): void {
                $table->renameColumn('features_json', 'features');
            });
        }
    }
};
