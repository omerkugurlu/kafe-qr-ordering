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
        Schema::create('loyalty_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cafe_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('order_count');
            $table->unsignedInteger('threshold')->default(10);
            $table->unsignedInteger('points_required')->default(0);
            $table->string('reward_type')->default('percent');
            $table->decimal('reward_value', 10, 2)->default(10);
            $table->decimal('minimum_order_total', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['cafe_id', 'is_active']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('loyalty_campaign_id')->nullable()->after('customer_id')->constrained('loyalty_campaigns')->nullOnDelete();
        });

        Schema::create('loyalty_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cafe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('points')->default(0);
            $table->unsignedInteger('completed_order_count')->default(0);
            $table->timestamps();
            $table->unique(['cafe_id', 'user_id']);
        });

        Schema::create('loyalty_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cafe_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('loyalty_campaigns')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->integer('points_delta')->default(0);
            $table->integer('orders_delta')->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('description');
            $table->timestamps();
            $table->index(['cafe_id', 'user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('loyalty_campaign_id');
        });
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_accounts');
        Schema::dropIfExists('loyalty_campaigns');
    }
};
