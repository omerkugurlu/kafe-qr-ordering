<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cafes', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('brand_name');
            $table->string('cover_path')->nullable()->after('logo_path');
            $table->text('description')->nullable()->after('cover_path');
            $table->string('phone', 40)->nullable()->after('description');
            $table->string('address')->nullable()->after('phone');
            $table->string('instagram', 120)->nullable()->after('address');
        });

        Schema::table('menu_categories', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('description');
        });

        Schema::table('menu_products', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('description');
            $table->unsignedSmallInteger('prep_minutes')->default(0)->after('price');
            $table->boolean('is_featured')->default(false)->after('is_available');
            $table->json('suggestions')->nullable()->after('is_featured');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('ready_at')->nullable()->after('served_at');
            $table->string('coupon_code', 40)->nullable()->after('discount_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['ready_at', 'coupon_code']);
        });

        Schema::table('menu_products', function (Blueprint $table): void {
            $table->dropColumn(['image_path', 'prep_minutes', 'is_featured', 'suggestions']);
        });

        Schema::table('menu_categories', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });

        Schema::table('cafes', function (Blueprint $table): void {
            $table->dropColumn(['logo_path', 'cover_path', 'description', 'phone', 'address', 'instagram']);
        });
    }
};
