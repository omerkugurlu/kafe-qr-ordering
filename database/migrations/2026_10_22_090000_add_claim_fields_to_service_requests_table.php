<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->foreignId('claimed_by')->nullable()->after('note')->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable()->after('claimed_by');
            $table->index(['branch_id', 'status', 'claimed_by']);
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'status', 'claimed_by']);
            $table->dropConstrainedForeignId('claimed_by');
            $table->dropColumn('claimed_at');
        });
    }
};
