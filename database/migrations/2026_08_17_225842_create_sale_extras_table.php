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
        Schema::create('sale_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->decimal('default_price_base', 19, 4);
            $table->string('type', 20);
            $table->unsignedBigInteger('inventory_product_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'sale_extras_company_name_unique');
            $table->unique(['company_id', 'id'], 'sale_extras_company_id_unique');
            $table->index(['company_id', 'is_active', 'name'], 'sale_extras_company_active_name_index');
            $table->foreign(['company_id', 'inventory_product_id'], 'sale_extras_inventory_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_extras');
    }
};
