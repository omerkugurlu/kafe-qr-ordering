<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('loyalty_campaigns', 'reward_product_id')) {
            Schema::table('loyalty_campaigns', function (Blueprint $table): void {
                $table->foreignId('reward_product_id')->nullable()->after('reward_type')->constrained('menu_products')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('loyalty_campaigns', 'reward_product_id')) {
            Schema::table('loyalty_campaigns', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('reward_product_id');
            });
        }
    }
};
