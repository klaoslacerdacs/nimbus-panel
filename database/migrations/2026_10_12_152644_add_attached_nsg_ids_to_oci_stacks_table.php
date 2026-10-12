<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Security groups created outside the panel (OCI console) and attached to the VM through Terraform.
     * Null means "not adopted yet": the next firewall run imports whatever is on the VNIC.
     */
    public function up(): void
    {
        Schema::table('oci_stacks', function (Blueprint $table) {
            $table->json('attached_nsg_ids')->nullable()->after('nsg_ocid');
        });
    }

    public function down(): void
    {
        Schema::table('oci_stacks', function (Blueprint $table) {
            $table->dropColumn('attached_nsg_ids');
        });
    }
};
