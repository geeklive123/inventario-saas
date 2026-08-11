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
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('role_id');
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['company_id', 'role_id', 'permission_id'],
                'role_permissions_assignment_unique',
            );
            $table->foreign(
                ['company_id', 'role_id'],
                'role_permissions_role_foreign',
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
        Schema::dropIfExists('role_permissions');
    }
};
