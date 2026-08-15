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
        Schema::create('product_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('version');
            $table->decimal('yield_quantity', 19, 6);
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->unsignedBigInteger('created_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'version'], 'recipes_product_version_unique');
            $table->unique(['company_id', 'product_id', 'active_slot'], 'recipes_one_active_unique');
            $table->unique(['company_id', 'id'], 'recipes_company_id_unique');
            $table->foreign(['company_id', 'product_id'], 'recipes_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['company_id', 'created_by_membership_id'], 'recipes_creator_foreign')
                ->references(['company_id', 'id'])->on('memberships')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_recipes');
    }
};
