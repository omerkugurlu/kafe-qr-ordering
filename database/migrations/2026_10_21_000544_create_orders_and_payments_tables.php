<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cafe_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('table_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('order_number');
            $table->string('source')->default('qr');
            $table->string('status')->default('pending')->index();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->text('customer_note')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'order_number']);
            $table->index(['branch_id', 'status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_product_id')->nullable()->constrained('menu_products')->nullOnDelete();
            $table->string('name_snapshot');
            $table->decimal('unit_price', 10, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_total', 10, 2);
            $table->string('customer_note')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('table_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method');
            $table->decimal('amount', 10, 2);
            $table->string('person_label')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['table_session_id', 'recorded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
