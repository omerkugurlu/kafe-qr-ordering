<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 80)->unique();
                $table->string('name', 80);
                $table->unsignedBigInteger('annual_price_cents')->default(0);
                $table->unsignedInteger('max_tables');
                $table->unsignedInteger('max_branches')->nullable();
                $table->json('features_json');
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('cafe_subscriptions')) {
            Schema::create('cafe_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cafe_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete();
                $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('plan_name_snapshot', 80);
                $table->string('status', 20)->default('active')->index();
                $table->dateTime('starts_at')->index();
                $table->dateTime('ends_at')->index();
                $table->unsignedBigInteger('annual_price_snapshot_cents');
                $table->unsignedInteger('max_tables_snapshot');
                $table->unsignedInteger('max_branches_snapshot')->nullable();
                $table->json('features_snapshot_json');
                $table->timestamps();
                $table->index(['cafe_id', 'status', 'starts_at', 'ends_at'], 'cafe_subscriptions_current_idx');
            });
        }

        if (!Schema::hasTable('cafe_subscription_requests')) {
            Schema::create('cafe_subscription_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('cafe_id')->constrained()->cascadeOnDelete();
                $table->foreignId('pending_cafe_id')->nullable()->unique()->constrained('cafes')->cascadeOnDelete();
                $table->foreignId('subscription_plan_id')->constrained('subscription_plans')->restrictOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('plan_name_snapshot', 80);
                $table->unsignedBigInteger('annual_price_snapshot_cents');
                $table->unsignedInteger('max_tables_snapshot');
                $table->unsignedInteger('max_branches_snapshot')->nullable();
                $table->json('features_snapshot_json');
                $table->string('status', 20)->default('pending')->index();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 250)->nullable();
                $table->foreignId('cafe_subscription_id')->nullable()->constrained('cafe_subscriptions')->nullOnDelete();
                $table->timestamp('requested_at')->index();
                $table->timestamps();
                $table->index(['cafe_id', 'requested_at'], 'cafe_subscription_requests_cafe_requested_idx');
            });
        }

        $now = now();
        $fullFeatures = [
            'customer_accounts' => true,
            'coupons' => true,
            'loyalty' => true,
            'reports' => true,
            'announcements' => true,
        ];
        $entryFeatures = array_fill_keys(array_keys($fullFeatures), false);

        if (Schema::hasColumn('subscription_plans', 'features_json')) {
            DB::table('subscription_plans')->insertOrIgnore([
                ['code' => 'giris', 'name' => 'Giriş', 'annual_price_cents' => 1000000, 'max_tables' => 10, 'max_branches' => 2, 'features_json' => json_encode($entryFeatures), 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'orta', 'name' => 'Orta', 'annual_price_cents' => 2000000, 'max_tables' => 20, 'max_branches' => null, 'features_json' => json_encode($fullFeatures), 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['code' => 'yuksek', 'name' => 'En Yüksek', 'annual_price_cents' => 5000000, 'max_tables' => 50, 'max_branches' => null, 'features_json' => json_encode($fullFeatures), 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cafe_subscription_requests');
        Schema::dropIfExists('cafe_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
