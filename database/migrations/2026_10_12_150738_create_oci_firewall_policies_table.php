<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared firewall rule sets attachable to many OCI stacks (VMs).
     */
    public function up(): void
    {
        Schema::create('oci_firewall_policies', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('rules');
            $table->timestamps();
        });

        Schema::create('oci_firewall_policy_oci_stack', function (Blueprint $table) {
            $table->foreignId('oci_firewall_policy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('oci_stack_id')->constrained()->cascadeOnDelete();
            $table->primary(['oci_firewall_policy_id', 'oci_stack_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oci_firewall_policy_oci_stack');
        Schema::dropIfExists('oci_firewall_policies');
    }
};
