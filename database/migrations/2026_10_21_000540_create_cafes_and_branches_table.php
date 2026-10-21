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
        Schema::create('cafes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active')->index();
            $table->string('registration_policy')->default('optional');
            $table->boolean('loyalty_enabled')->default(false);
            $table->unsignedInteger('points_per_100')->default(1);
            $table->string('brand_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cafe_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('address')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('uses_shared_menu')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['cafe_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branches');
        Schema::dropIfExists('cafes');
    }
};
