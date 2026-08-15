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
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 19, 6)->default(0);
            $table->decimal('inventory_value_base', 19, 4)->default(0);
            $table->decimal('average_unit_cost_base', 19, 4)->default(0);
            $table->decimal('last_inbound_unit_cost_base', 19, 4)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'warehouse_id', 'product_id'], 'stock_balances_warehouse_product_unique');
            $table->unique(['company_id', 'id'], 'stock_balances_company_id_unique');
            $table->index(['company_id', 'product_id'], 'stock_balances_product_index');
            $table->foreign(['company_id', 'warehouse_id'], 'stock_balances_warehouse_foreign')
                ->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['company_id', 'product_id'], 'stock_balances_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
