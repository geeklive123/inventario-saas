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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sequence_number');
            $table->string('number', 20);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('expense_category_id');
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->string('category_name');
            $table->string('payment_method_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('reference')->nullable();
            $table->string('concept');
            $table->decimal('amount_base', 19, 4);
            $table->timestamp('occurred_at');
            $table->text('notes')->nullable();
            $table->string('status', 20);
            $table->unsignedBigInteger('cancelled_by_membership_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'sequence_number'], 'expenses_company_sequence_unique');
            $table->unique(['company_id', 'number'], 'expenses_company_number_unique');
            $table->unique(['company_id', 'id'], 'expenses_company_id_unique');
            $table->index(['company_id', 'status', 'occurred_at'], 'expenses_company_status_date_index');
            $table->index(['company_id', 'expense_category_id', 'occurred_at'], 'expenses_company_category_date_index');
            $table->index(['company_id', 'payment_method_id', 'occurred_at'], 'expenses_company_payment_date_index');
            $table->index(['company_id', 'membership_id', 'occurred_at'], 'expenses_company_member_date_index');
            $table->index(['company_id', 'branch_id', 'occurred_at'], 'expenses_company_branch_date_index');
            $table->foreign(['company_id', 'branch_id'], 'expenses_branch_foreign')
                ->references(['company_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'membership_id'], 'expenses_membership_foreign')
                ->references(['company_id', 'id'])->on('memberships')->restrictOnDelete();
            $table->foreign(['company_id', 'expense_category_id'], 'expenses_category_foreign')
                ->references(['company_id', 'id'])->on('expense_categories')->restrictOnDelete();
            $table->foreign(['company_id', 'payment_method_id'], 'expenses_payment_method_foreign')
                ->references(['company_id', 'id'])->on('payment_methods')->restrictOnDelete();
            $table->foreign(['company_id', 'cancelled_by_membership_id'], 'expenses_canceller_foreign')
                ->references(['company_id', 'id'])->on('memberships')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
