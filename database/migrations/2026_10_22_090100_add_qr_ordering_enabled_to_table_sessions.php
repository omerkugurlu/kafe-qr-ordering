<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_sessions', function (Blueprint $table): void {
            $table->boolean('qr_ordering_enabled')->default(true)->after('status');
            $table->index(['dining_table_id', 'status', 'qr_ordering_enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('table_sessions', function (Blueprint $table): void {
            $table->dropIndex(['dining_table_id', 'status', 'qr_ordering_enabled']);
            $table->dropColumn('qr_ordering_enabled');
        });
    }
};
