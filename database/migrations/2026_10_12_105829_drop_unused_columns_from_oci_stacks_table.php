<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oci_stacks', function (Blueprint $table) {
            $table->dropColumn(['config_source', 'config_url', 'managed_by']);
        });
    }

    public function down(): void
    {
        Schema::table('oci_stacks', function (Blueprint $table) {
            $table->string('config_source')->default('template_zip');
            $table->string('config_url')->nullable();
            $table->string('managed_by')->default('terraform');
        });
    }
};
