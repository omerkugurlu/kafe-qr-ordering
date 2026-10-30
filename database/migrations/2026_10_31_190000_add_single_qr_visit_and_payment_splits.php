<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_sessions', function (Blueprint $table): void {
            $table->char('qr_visit_token_hash', 64)->nullable()->after('qr_ordering_enabled');
            $table->json('qr_visit_metadata')->nullable()->after('qr_visit_token_hash');
        });

        Schema::create('payment_split_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('table_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('people_count');
            $table->decimal('initial_amount', 10, 2);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['table_session_id', 'closed_at']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->uuid('split_group_id')->nullable()->after('person_label');
            $table->unsignedTinyInteger('split_person_number')->nullable()->after('split_group_id');
            $table->foreign('split_group_id')->references('id')->on('payment_split_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['split_group_id']);
            $table->dropColumn(['split_group_id', 'split_person_number']);
        });

        Schema::dropIfExists('payment_split_groups');

        Schema::table('table_sessions', function (Blueprint $table): void {
            $table->dropColumn(['qr_visit_token_hash', 'qr_visit_metadata']);
        });
    }
};
