<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cafes', 'loyalty_spend_threshold')) {
            Schema::table('cafes', function (Blueprint $table): void {
                $table->unsignedInteger('loyalty_spend_threshold')->default(100)->after('points_per_100');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cafes', 'loyalty_spend_threshold')) {
            Schema::table('cafes', function (Blueprint $table): void {
                $table->dropColumn('loyalty_spend_threshold');
            });
        }
    }
};
