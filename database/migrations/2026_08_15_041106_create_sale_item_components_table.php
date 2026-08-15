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
        Schema::create('sale_item_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_item_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name');
            $table->string('product_sku', 100);
            $table->string('unit_symbol', 20);
            $table->decimal('recipe_quantity', 19, 6);
            $table->decimal('waste_percentage', 9, 6);
            $table->decimal('quantity_consumed', 19, 6);
            $table->decimal('unit_cost_base', 19, 4);
            $table->decimal('total_cost_base', 19, 4);
            $table->timestamps();

            $table->unique(['company_id', 'sale_item_id', 'product_id'], 'sale_item_components_product_unique');
            $table->index(['company_id', 'product_id', 'created_at'], 'sale_item_components_product_date_index');
            $table->foreign(['company_id', 'sale_item_id'], 'sale_item_components_item_foreign')
                ->references(['company_id', 'id'])->on('sale_items')->restrictOnDelete();
            $table->foreign(['company_id', 'product_id'], 'sale_item_components_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_item_components');
    }
};
