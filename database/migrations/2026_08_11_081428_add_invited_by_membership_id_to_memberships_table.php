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
        Schema::table('memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('invited_by_membership_id')->nullable()->after('is_owner');

            $table->foreign(
                ['company_id', 'invited_by_membership_id'],
                'memberships_inviter_foreign',
            )->references(['company_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign('memberships_inviter_foreign');
            $table->dropColumn('invited_by_membership_id');
        });
    }
};
