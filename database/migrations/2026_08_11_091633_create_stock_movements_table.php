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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('type', 30);
            $table->unsignedBigInteger('reversal_of_movement_id')->nullable();
            $table->unsignedBigInteger('created_by_membership_id');
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'id'], 'stock_movements_company_id_unique');
            $table->unique(['company_id', 'reversal_of_movement_id'], 'stock_movements_one_reversal_unique');
            $table->index(['company_id', 'warehouse_id', 'occurred_at'], 'stock_movements_warehouse_date_index');
            $table->index(['company_id', 'type', 'occurred_at'], 'stock_movements_type_date_index');
            $table->foreign(['company_id', 'warehouse_id'], 'stock_movements_warehouse_foreign')
                ->references(['company_id', 'id'])->on('warehouses')->restrictOnDelete();
            $table->foreign(['company_id', 'created_by_membership_id'], 'stock_movements_creator_foreign')
                ->references(['company_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['company_id', 'reversal_of_movement_id'], 'stock_movements_reversal_foreign')
                ->references(['company_id', 'id'])->on('stock_movements')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
