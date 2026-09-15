<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('inventory_status', 30)->default('complete')->after('payment_status');
            $table->decimal('total_cost_base', 19, 4)->nullable()->change();
            $table->decimal('gross_margin_base', 19, 4)->nullable()->change();
            $table->index(['company_id', 'inventory_status', 'occurred_at'], 'sales_company_inventory_date_index');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost_base', 19, 4)->nullable()->change();
            $table->decimal('total_cost_base', 19, 4)->nullable()->change();
            $table->decimal('gross_margin_base', 19, 4)->nullable()->change();
        });

        Schema::table('sale_item_components', function (Blueprint $table) {
            $table->decimal('unit_cost_base', 19, 4)->nullable()->change();
            $table->decimal('total_cost_base', 19, 4)->nullable()->change();
            $table->decimal('customization_total_cost_base', 19, 4)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('sale_item_components')->whereNull('unit_cost_base')->update(['unit_cost_base' => 0]);
        DB::table('sale_item_components')->whereNull('total_cost_base')->update(['total_cost_base' => 0]);
        DB::table('sale_item_components')->whereNull('customization_total_cost_base')->update(['customization_total_cost_base' => 0]);
        DB::table('sale_items')->whereNull('unit_cost_base')->update(['unit_cost_base' => 0]);
        DB::table('sale_items')->whereNull('total_cost_base')->update(['total_cost_base' => 0]);
        DB::table('sale_items')->whereNull('gross_margin_base')->update(['gross_margin_base' => 0]);
        DB::table('sales')->whereNull('total_cost_base')->update(['total_cost_base' => 0]);
        DB::table('sales')->whereNull('gross_margin_base')->update(['gross_margin_base' => 0]);

        Schema::table('sale_item_components', function (Blueprint $table) {
            $table->decimal('unit_cost_base', 19, 4)->nullable(false)->change();
            $table->decimal('total_cost_base', 19, 4)->nullable(false)->change();
            $table->decimal('customization_total_cost_base', 19, 4)->nullable(false)->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost_base', 19, 4)->nullable(false)->change();
            $table->decimal('total_cost_base', 19, 4)->nullable(false)->change();
            $table->decimal('gross_margin_base', 19, 4)->nullable(false)->change();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_company_inventory_date_index');
            $table->dropColumn('inventory_status');
            $table->decimal('total_cost_base', 19, 4)->nullable(false)->change();
            $table->decimal('gross_margin_base', 19, 4)->nullable(false)->change();
        });
    }
};
