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
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('payment_method_id');
            $table->string('payment_method_name');
            $table->decimal('amount_base', 19, 4);
            $table->timestamps();

            $table->unique(['company_id', 'sale_id', 'payment_method_id'], 'sale_payments_method_unique');
            $table->index(['company_id', 'payment_method_id', 'created_at'], 'sale_payments_method_date_index');
            $table->foreign(['company_id', 'sale_id'], 'sale_payments_sale_foreign')
                ->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['company_id', 'payment_method_id'], 'sale_payments_method_foreign')
                ->references(['company_id', 'id'])->on('payment_methods')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
