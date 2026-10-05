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
        Schema::table('sale_item_components', function (Blueprint $table) {
            $table->index(['company_id', 'sale_item_id'], 'sale_item_components_item_index');
        });

        Schema::table('sale_item_components', function (Blueprint $table) {
            $table->dropUnique('sale_item_components_product_unique');
            $table->string('source_key', 100)->nullable()->after('sale_item_id');
            $table->unsignedBigInteger('original_product_id')->nullable()->after('source_key');
            $table->string('original_product_name')->nullable()->after('original_product_id');
            $table->string('original_product_sku', 100)->nullable()->after('original_product_name');
            $table->string('original_unit_symbol', 20)->nullable()->after('original_product_sku');
            $table->decimal('original_quantity_required', 19, 6)->nullable()->after('waste_percentage');
            $table->decimal('quantity_required', 19, 6)->nullable()->after('original_quantity_required');

            $table->unique(['company_id', 'id'], 'sale_item_components_company_id_unique');
            $table->unique(['company_id', 'sale_item_id', 'source_key'], 'sale_item_components_source_unique');
            $table->foreign(['company_id', 'original_product_id'], 'sale_item_components_original_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });

        Schema::table('sale_inventory_pendings', function (Blueprint $table) {
            $table->index(['company_id', 'sale_item_id'], 'sale_inv_pending_item_index');
        });

        Schema::table('sale_inventory_pendings', function (Blueprint $table) {
            $table->dropUnique('sale_inv_pending_component_unique');
            $table->unsignedBigInteger('sale_item_component_id')->nullable()->after('sale_item_id');
            $table->unsignedBigInteger('planned_component_product_id')->nullable()->after('original_component_product_id');
            $table->string('planned_component_name')->nullable()->after('planned_component_product_id');
            $table->string('planned_component_sku', 100)->nullable()->after('planned_component_name');
            $table->string('planned_unit_symbol', 20)->nullable()->after('planned_component_sku');

            $table->unique(['company_id', 'sale_item_component_id'], 'sale_inv_pending_snapshot_unique');
            $table->foreign(['company_id', 'sale_item_component_id'], 'sale_inv_pending_snapshot_foreign')
                ->references(['company_id', 'id'])->on('sale_item_components')->restrictOnDelete();
            $table->foreign(['company_id', 'planned_component_product_id'], 'sale_inv_pending_planned_product_foreign')
                ->references(['company_id', 'id'])->on('products')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_inventory_pendings', function (Blueprint $table) {
            $table->dropForeign('sale_inv_pending_snapshot_foreign');
            $table->dropForeign('sale_inv_pending_planned_product_foreign');
            $table->dropUnique('sale_inv_pending_snapshot_unique');
            $table->dropColumn([
                'sale_item_component_id',
                'planned_component_product_id',
                'planned_component_name',
                'planned_component_sku',
                'planned_unit_symbol',
            ]);
            $table->unique(
                ['company_id', 'sale_item_id', 'original_component_product_id'],
                'sale_inv_pending_component_unique',
            );
        });

        Schema::table('sale_inventory_pendings', function (Blueprint $table) {
            $table->dropIndex('sale_inv_pending_item_index');
        });

        Schema::table('sale_item_components', function (Blueprint $table) {
            $table->dropForeign('sale_item_components_original_product_foreign');
            $table->dropUnique('sale_item_components_source_unique');
            $table->dropUnique('sale_item_components_company_id_unique');
            $table->dropColumn([
                'source_key',
                'original_product_id',
                'original_product_name',
                'original_product_sku',
                'original_unit_symbol',
                'original_quantity_required',
                'quantity_required',
            ]);
            $table->unique(
                ['company_id', 'sale_item_id', 'product_id'],
                'sale_item_components_product_unique',
            );
        });

        Schema::table('sale_item_components', function (Blueprint $table) {
            $table->dropIndex('sale_item_components_item_index');
        });
    }
};
