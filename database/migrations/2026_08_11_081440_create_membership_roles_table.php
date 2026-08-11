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
        Schema::create('membership_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('membership_id');
            $table->unsignedBigInteger('role_id');
            $table->timestamps();

            $table->unique(
                ['company_id', 'membership_id', 'role_id'],
                'membership_roles_assignment_unique',
            );
            $table->foreign(
                ['company_id', 'membership_id'],
                'membership_roles_membership_foreign',
            )->references(['company_id', 'id'])
                ->on('memberships')
                ->restrictOnDelete();
            $table->foreign(
                ['company_id', 'role_id'],
                'membership_roles_role_foreign',
            )->references(['company_id', 'id'])
                ->on('roles')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_roles');
    }
};
