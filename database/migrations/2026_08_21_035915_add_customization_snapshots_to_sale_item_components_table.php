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
            $table->decimal('customization_quantity', 19, 6)->default(0)->after('recipe_quantity');
            $table->decimal('customization_quantity_consumed', 19, 6)->default(0)->after('quantity_consumed');
            $table->decimal('customization_total_cost_base', 19, 4)->default(0)->after('total_cost_base');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_item_components', function (Blueprint $table) {
            $table->dropColumn([
                'customization_quantity',
                'customization_quantity_consumed',
                'customization_total_cost_base',
            ]);
        });
    }
};
