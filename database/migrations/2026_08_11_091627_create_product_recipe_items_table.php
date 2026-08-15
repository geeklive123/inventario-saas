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
        Schema::create('product_recipe_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_recipe_id');
            $table->unsignedBigInteger('component_product_id');
            $table->decimal('quantity', 19, 6);
            $table->decimal('waste_percentage', 9, 6)->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'product_recipe_id', 'component_product_id'], 'recipe_items_component_unique');
            $table->foreign(['company_id', 'product_recipe_id'], 'recipe_items_recipe_foreign')
                ->references(['company_id', 'id'])->on('product_recipes')->restrictOnDelete();
            $table->foreign(['company_id', 'component_product_id'], 'recipe_items_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_recipe_items');
    }
};
