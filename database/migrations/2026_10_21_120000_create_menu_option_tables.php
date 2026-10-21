<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_option_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_product_id')->constrained('menu_products')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('selection_mode', 12)->default('single');
            $table->unsignedTinyInteger('min_selections')->default(0);
            $table->unsignedTinyInteger('max_selections')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['menu_product_id', 'is_active', 'sort_order']);
        });

        Schema::create('menu_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_option_group_id')->constrained('menu_option_groups')->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('price_delta', 10, 2)->default(0);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['menu_option_group_id', 'is_available', 'sort_order']);
        });

        Schema::create('order_item_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_option_id')->nullable()->constrained('menu_options')->nullOnDelete();
            $table->string('group_name_snapshot', 80);
            $table->string('name_snapshot', 80);
            $table->decimal('price_delta', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('menu_options');
        Schema::dropIfExists('menu_option_groups');
    }
};
