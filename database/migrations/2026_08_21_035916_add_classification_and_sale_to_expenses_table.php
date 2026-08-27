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
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('type', 20)->default('indirect')->after('number');
            $table->unsignedBigInteger('sale_id')->nullable()->after('branch_id');
            $table->index(['company_id', 'type', 'occurred_at'], 'expenses_company_type_date_index');
            $table->index(['company_id', 'sale_id'], 'expenses_company_sale_index');
            $table->foreign(['company_id', 'sale_id'], 'expenses_sale_foreign')
                ->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign('expenses_sale_foreign');
            $table->dropIndex('expenses_company_type_date_index');
            $table->dropIndex('expenses_company_sale_index');
            $table->dropColumn(['type', 'sale_id']);
        });
    }
};
