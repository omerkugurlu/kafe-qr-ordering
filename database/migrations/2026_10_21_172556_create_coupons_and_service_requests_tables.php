<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cafe_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('type', 20)->default('percent');
            $table->decimal('value', 10, 2);
            $table->decimal('min_subtotal', 10, 2)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['cafe_id', 'code']);
            $table->index(['cafe_id', 'is_active']);
        });

        Schema::create('service_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('waiter');
            $table->string('status', 20)->default('open');
            $table->string('note', 160)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->nullable()->after('loyalty_campaign_id')->constrained('coupons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_id');
        });

        Schema::dropIfExists('service_requests');
        Schema::dropIfExists('coupons');
    }
};
