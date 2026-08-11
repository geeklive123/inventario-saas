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
        Schema::create('company_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('module_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('disabled');
            $table->json('settings')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedBigInteger('enabled_by_membership_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'module_id'], 'company_modules_company_module_unique');
            $table->index(['company_id', 'status'], 'company_modules_company_status_index');
            $table->foreign(
                ['company_id', 'enabled_by_membership_id'],
                'company_modules_enabler_foreign',
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
        Schema::dropIfExists('company_modules');
    }
};
