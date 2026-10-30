<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->decimal('cash_received', 10, 2)->nullable()->after('amount');
            $table->decimal('change_amount', 10, 2)->default(0)->after('cash_received');
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->decimal('amount', 10, 2);
            $table->timestamps();
            $table->index(['order_item_id', 'payment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['cash_received', 'change_amount']);
        });
    }
};
