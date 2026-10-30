<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $addedLegacyAccessColumn = ! Schema::hasColumn('cafes', 'subscription_legacy_unrestricted');
        if ($addedLegacyAccessColumn) {
            Schema::table('cafes', function (Blueprint $table): void {
                $table->boolean('subscription_legacy_unrestricted')->default(false)->after('registration_policy');
            });

            DB::table('cafes')->update(['subscription_legacy_unrestricted' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cafes', 'subscription_legacy_unrestricted')) {
            Schema::table('cafes', function (Blueprint $table): void {
                $table->dropColumn('subscription_legacy_unrestricted');
            });
        }
    }
};
