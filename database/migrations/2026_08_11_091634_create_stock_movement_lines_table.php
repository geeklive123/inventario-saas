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
        Schema::create('stock_movement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('stock_movement_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 19, 6);
            $table->decimal('quantity_before', 19, 6);
            $table->decimal('quantity_after', 19, 6);
            $table->decimal('unit_cost_base', 19, 4);
            $table->decimal('total_cost_base', 19, 4);
            $table->decimal('inventory_value_before_base', 19, 4);
            $table->decimal('inventory_value_after_base', 19, 4);
            $table->decimal('average_unit_cost_before_base', 19, 4);
            $table->decimal('average_unit_cost_after_base', 19, 4);
            $table->decimal('cost_variance_base', 19, 4)->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'stock_movement_id', 'product_id'], 'stock_movement_lines_product_unique');
            $table->index(['company_id', 'product_id', 'created_at'], 'stock_movement_lines_product_date_index');
            $table->foreign(['company_id', 'stock_movement_id'], 'stock_movement_lines_movement_foreign')
                ->references(['company_id', 'id'])->on('stock_movements')->restrictOnDelete();
            $table->foreign(['company_id', 'product_id'], 'stock_movement_lines_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movement_lines');
    }
};
