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
            $table->string('order_status', 20)->default('reserved')->after('status');
            $table->string('payment_status', 20)->default('pending')->after('order_status');
            $table->decimal('extras_total_base', 19, 4)->default(0)->after('subtotal_base');
            $table->decimal('paid_total_base', 19, 4)->default(0)->after('total_base');
            $table->decimal('balance_due_base', 19, 4)->default(0)->after('paid_total_base');

            $table->index(['company_id', 'status', 'payment_status'], 'sales_company_record_payment_index');
            $table->index(['company_id', 'order_status', 'occurred_at'], 'sales_company_order_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_company_record_payment_index');
            $table->dropIndex('sales_company_order_date_index');
            $table->dropColumn([
                'order_status', 'payment_status', 'extras_total_base',
                'paid_total_base', 'balance_due_base',
            ]);
        });
    }
};
