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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('sku', 100);
            $table->string('barcode', 100)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('item_type', 30);
            $table->string('inventory_behavior', 30);
            $table->boolean('is_sellable')->default(true);
            $table->decimal('sale_price_base', 19, 4)->default(0);
            $table->decimal('fallback_unit_cost_base', 19, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'sku'], 'products_company_sku_unique');
            $table->unique(['company_id', 'barcode'], 'products_company_barcode_unique');
            $table->unique(['company_id', 'id'], 'products_company_id_unique');
            $table->index(['company_id', 'item_type', 'inventory_behavior'], 'products_company_type_index');
            $table->index(['company_id', 'is_active', 'is_sellable'], 'products_company_active_sellable_index');
            $table->foreign(['company_id', 'unit_id'], 'products_unit_foreign')
                ->references(['company_id', 'id'])->on('units')->restrictOnDelete();
            $table->foreign(['company_id', 'category_id'], 'products_category_foreign')
                ->references(['company_id', 'id'])->on('categories')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
