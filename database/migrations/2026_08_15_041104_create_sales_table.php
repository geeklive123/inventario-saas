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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence_number');
            $table->string('number', 20);
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('branch_name');
            $table->string('warehouse_name');
            $table->string('status', 20);
            $table->decimal('subtotal_base', 19, 4);
            $table->decimal('total_base', 19, 4);
            $table->decimal('total_cost_base', 19, 4);
            $table->decimal('gross_margin_base', 19, 4);
            $table->unsignedBigInteger('confirmed_by_membership_id');
            $table->unsignedBigInteger('voided_by_membership_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'sequence_number'], 'sales_company_sequence_unique');
            $table->unique(['company_id', 'number'], 'sales_company_number_unique');
            $table->unique(['company_id', 'id'], 'sales_company_id_unique');
            $table->index(['company_id', 'occurred_at'], 'sales_company_date_index');
            $table->index(['company_id', 'status', 'occurred_at'], 'sales_company_status_date_index');
            $table->index(['company_id', 'customer_id'], 'sales_company_customer_index');
            $table->foreign(['company_id', 'branch_id'], 'sales_branch_foreign')
                ->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'warehouse_id'], 'sales_warehouse_foreign')
                ->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['company_id', 'confirmed_by_membership_id'], 'sales_confirmer_foreign')
                ->references(['company_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['company_id', 'voided_by_membership_id'], 'sales_voider_foreign')
                ->references(['company_id', 'id'])->on('memberships')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
