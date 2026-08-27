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
        Schema::table('sales', function (Blueprint $table) {
            $table->timestamp('delivery_at')->nullable()->after('occurred_at');
            $table->unsignedBigInteger('delivery_updated_by_membership_id')->nullable()->after('delivery_at');
            $table->index(['company_id', 'order_status', 'delivery_at'], 'sales_company_order_delivery_index');
            $table->foreign(['company_id', 'delivery_updated_by_membership_id'], 'sales_delivery_updater_foreign')
                ->references(['company_id', 'id'])->on('memberships')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign('sales_delivery_updater_foreign');
            $table->dropIndex('sales_company_order_delivery_index');
            $table->dropColumn(['delivery_at', 'delivery_updated_by_membership_id']);
        });
    }
};
