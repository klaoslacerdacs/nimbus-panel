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
        Schema::create('oci_connections', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('authentication_method')->default('api_key');
            $table->string('region')->nullable();
            $table->string('compartment_ocid')->nullable();
            $table->string('tenancy_ocid')->nullable();
            $table->string('user_ocid')->nullable();
            $table->string('fingerprint')->nullable();
            $table->longText('private_key')->nullable();
            $table->text('passphrase')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oci_connections');
    }
};
