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
        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cafe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['cafe_id', 'branch_id', 'is_active']);
        });

        Schema::create('menu_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cafe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->constrained('menu_categories')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['cafe_id', 'branch_id', 'is_available']);
        });

        Schema::create('branch_product', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_product_id')->constrained('menu_products')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->boolean('is_available')->nullable();
            $table->timestamps();
            $table->primary(['branch_id', 'menu_product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_product');
        Schema::dropIfExists('menu_products');
        Schema::dropIfExists('menu_categories');
    }
};
