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
        Schema::create('sale_inventory_regularization_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_inventory_pending_id');
            $table->unsignedBigInteger('actual_product_id');
            $table->string('actual_product_name');
            $table->string('actual_product_sku', 100);
            $table->string('unit_symbol', 20);
            $table->decimal('quantity', 19, 6);
            $table->decimal('unit_cost_base', 19, 4);
            $table->decimal('total_cost_base', 19, 4);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['company_id', 'sale_inventory_pending_id', 'actual_product_id'],
                'sale_inv_reg_line_product_unique',
            );
            $table->index(['company_id', 'actual_product_id', 'created_at'], 'sale_inv_reg_line_product_date_index');
            $table->foreign(['company_id', 'sale_inventory_pending_id'], 'sale_inv_reg_line_pending_foreign')
                ->references(['company_id', 'id'])->on('sale_inventory_pendings')->restrictOnDelete();
            $table->foreign(['company_id', 'actual_product_id'], 'sale_inv_reg_line_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_inventory_regularization_lines');
    }
};
