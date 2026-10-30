<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('loyalty_campaigns', 'reward_product_ids')) {
            Schema::table('loyalty_campaigns', function (Blueprint $table): void {
                $table->json('reward_product_ids')->nullable()->after('reward_product_id');
            });
        }

        if (Schema::hasColumn('loyalty_campaigns', 'reward_product_id')) {
            DB::table('loyalty_campaigns')
                ->whereNotNull('reward_product_id')
                ->whereNull('reward_product_ids')
                ->orderBy('id')
                ->each(function (object $campaign): void {
                    DB::table('loyalty_campaigns')->where('id', $campaign->id)->update([
                        'reward_product_ids' => json_encode([(int) $campaign->reward_product_id]),
                    ]);
                });
        }

        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'is_loyalty_reward')) {
                $table->boolean('is_loyalty_reward')->default(false)->after('line_total');
            }
            if (! Schema::hasColumn('order_items', 'list_unit_price')) {
                $table->decimal('list_unit_price', 10, 2)->nullable()->after('is_loyalty_reward');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $columns = array_values(array_filter(['is_loyalty_reward', 'list_unit_price'], fn (string $column): bool => Schema::hasColumn('order_items', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        if (Schema::hasColumn('loyalty_campaigns', 'reward_product_ids')) {
            Schema::table('loyalty_campaigns', function (Blueprint $table): void {
                $table->dropColumn('reward_product_ids');
            });
        }
    }
};
