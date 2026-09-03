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
            $table->decimal('customization_unit_price_base', 19, 4)
                ->default(0)
                ->after('customization_quantity_consumed');
            $table->decimal('customization_total_price_base', 19, 4)
                ->default(0)
                ->after('customization_unit_price_base');
            $table->string('customization_note', 500)
                ->nullable()
                ->after('customization_total_price_base');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_item_components', function (Blueprint $table) {
            $table->dropColumn([
                'customization_unit_price_base',
                'customization_total_price_base',
                'customization_note',
            ]);
        });
    }
};
