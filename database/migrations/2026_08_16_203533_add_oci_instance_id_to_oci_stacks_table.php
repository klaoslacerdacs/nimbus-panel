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
        Schema::table('oci_stacks', function (Blueprint $table) {
            $table->string('oci_instance_id')->nullable()->after('stack_ocid');
        });
    }

    public function down(): void
    {
        Schema::table('oci_stacks', function (Blueprint $table) {
            $table->dropColumn('oci_instance_id');
        });
    }
};
