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
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('received_by_membership_id')->nullable(false)->change();
            $table->timestamp('occurred_at')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('received_by_membership_id')->nullable()->change();
            $table->timestamp('occurred_at')->nullable()->change();
        });
    }
};
