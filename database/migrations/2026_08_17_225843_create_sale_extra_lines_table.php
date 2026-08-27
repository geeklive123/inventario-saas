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
        Schema::create('sale_extra_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('sale_extra_id');
            $table->string('extra_name');
            $table->string('extra_type', 20);
            $table->decimal('quantity', 19, 6);
            $table->decimal('unit_price_base', 19, 4);
            $table->decimal('subtotal_base', 19, 4);
            $table->timestamps();

            $table->unique(['company_id', 'id'], 'sale_extra_lines_company_id_unique');
            $table->unique(['company_id', 'sale_id', 'sale_extra_id'], 'sale_extra_lines_extra_unique');
            $table->index(['company_id', 'sale_extra_id', 'created_at'], 'sale_extra_lines_extra_date_index');
            $table->foreign(['company_id', 'sale_id'], 'sale_extra_lines_sale_foreign')
                ->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_extra_id'], 'sale_extra_lines_catalog_foreign')
                ->references(['company_id', 'id'])->on('sale_extras')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_extra_lines');
    }
};
