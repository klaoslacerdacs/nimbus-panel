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
            $table->string('nsg_ocid')->nullable()->after('vcn_ocid');
            $table->json('firewall_rules')->nullable()->after('nsg_ocid');
        });
    }

    public function down(): void
    {
        Schema::table('oci_stacks', function (Blueprint $table) {
            $table->dropColumn(['nsg_ocid', 'firewall_rules']);
        });
    }
};
