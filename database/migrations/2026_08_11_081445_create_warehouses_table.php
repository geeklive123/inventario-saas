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
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['company_id', 'branch_id', 'code'],
                'warehouses_branch_code_unique',
            );
            $table->unique(['company_id', 'id'], 'warehouses_company_id_unique');
            $table->index(['company_id', 'branch_id', 'is_active'], 'warehouses_branch_active_index');
            $table->foreign(
                ['company_id', 'branch_id'],
                'warehouses_branch_foreign',
            )->references(['company_id', 'id'])
                ->on('branches')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
