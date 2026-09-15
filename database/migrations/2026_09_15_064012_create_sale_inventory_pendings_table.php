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
        Schema::create('sale_inventory_pendings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('sale_item_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('original_component_product_id');
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->string('original_component_name');
            $table->string('original_component_sku', 100);
            $table->string('unit_symbol', 20);
            $table->decimal('required_quantity', 19, 6);
            $table->decimal('regularized_quantity', 19, 6)->default(0);
            $table->string('status', 20)->default('pending');
            $table->timestamp('regularized_at')->nullable();
            $table->unsignedBigInteger('regularized_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'sale_inv_pending_company_id_unique');
            $table->unique(
                ['company_id', 'sale_item_id', 'original_component_product_id'],
                'sale_inv_pending_component_unique',
            );
            $table->unique(['company_id', 'stock_movement_id'], 'sale_inv_pending_movement_unique');
            $table->index(['company_id', 'status', 'created_at'], 'sale_inv_pending_status_date_index');
            $table->index(['company_id', 'warehouse_id', 'status'], 'sale_inv_pending_warehouse_index');
            $table->index(['company_id', 'sale_id', 'status'], 'sale_inv_pending_sale_index');
            $table->foreign(['company_id', 'sale_id'], 'sale_inv_pending_sale_foreign')
                ->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_item_id'], 'sale_inv_pending_item_foreign')
                ->references(['company_id', 'id'])->on('sale_items')->restrictOnDelete();
            $table->foreign(['company_id', 'warehouse_id'], 'sale_inv_pending_warehouse_foreign')
                ->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['company_id', 'original_component_product_id'], 'sale_inv_pending_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['company_id', 'stock_movement_id'], 'sale_inv_pending_movement_foreign')
                ->references(['company_id', 'id'])->on('stock_movements')->restrictOnDelete();
            $table->foreign(['company_id', 'regularized_by_membership_id'], 'sale_inv_pending_member_foreign')
                ->references(['company_id', 'id'])->on('memberships')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_inventory_pendings');
    }
};
