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
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_recipe_id');
            $table->unsignedInteger('recipe_version');
            $table->string('product_name');
            $table->string('product_sku', 100);
            $table->string('unit_symbol', 20);
            $table->decimal('quantity', 19, 6);
            $table->decimal('unit_price_base', 19, 4);
            $table->decimal('subtotal_base', 19, 4);
            $table->decimal('unit_cost_base', 19, 4);
            $table->decimal('total_cost_base', 19, 4);
            $table->decimal('gross_margin_base', 19, 4);
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'sale_items_company_id_unique');
            $table->unique(['company_id', 'sale_id', 'product_id'], 'sale_items_product_unique');
            $table->index(['company_id', 'product_id', 'created_at'], 'sale_items_product_date_index');
            $table->foreign(['company_id', 'sale_id'], 'sale_items_sale_foreign')
                ->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['company_id', 'product_id'], 'sale_items_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['company_id', 'product_recipe_id'], 'sale_items_recipe_foreign')
                ->references(['company_id', 'id'])->on('product_recipes')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
