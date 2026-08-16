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
        Schema::create('oci_stacks', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('oci_connection_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('stack_ocid')->nullable();
            $table->string('compartment_ocid');
            $table->string('region');
            $table->string('config_source')->default('template_zip');
            $table->string('config_url')->nullable();
            $table->json('tf_vars')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('managed_by')->default('terraform');
            $table->text('last_plan_summary')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oci_stacks');
    }
};
