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
            $table->index(['company_id', 'sale_id', 'payment_method_id'], 'sale_payments_sale_method_index');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique('sale_payments_method_unique');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('received_by_membership_id')->nullable()->after('payment_method_name');
            $table->timestamp('occurred_at')->nullable()->after('amount_base');

            $table->index(['company_id', 'sale_id', 'occurred_at'], 'sale_payments_sale_date_index');
            $table->index(['company_id', 'occurred_at'], 'sale_payments_company_date_index');
            $table->foreign(['company_id', 'received_by_membership_id'], 'sale_payments_receiver_foreign')
                ->references(['company_id', 'id'])->on('memberships')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropForeign('sale_payments_receiver_foreign');
            $table->dropIndex('sale_payments_sale_date_index');
            $table->dropIndex('sale_payments_company_date_index');
            $table->dropColumn(['received_by_membership_id', 'occurred_at']);
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->unique(['company_id', 'sale_id', 'payment_method_id'], 'sale_payments_method_unique');
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropIndex('sale_payments_sale_method_index');
        });
    }
};
