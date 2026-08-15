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
        Schema::create('sale_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('stock_movement_id');
            $table->string('kind', 20);
            $table->timestamps();

            $table->unique(['company_id', 'sale_id', 'kind'], 'sale_stock_movements_kind_unique');
            $table->unique(['company_id', 'stock_movement_id'], 'sale_stock_movements_movement_unique');
            $table->foreign(['company_id', 'sale_id'], 'sale_stock_movements_sale_foreign')
                ->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['company_id', 'stock_movement_id'], 'sale_stock_movements_movement_foreign')
                ->references(['company_id', 'id'])->on('stock_movements')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_stock_movements');
    }
};
