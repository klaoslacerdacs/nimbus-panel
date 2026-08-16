<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'oci_connection_id')) {
                $table->foreignId('oci_connection_id')->nullable()->after('cloud_provider_token_id')
                    ->constrained('oci_connections')->onDelete('set null');
            }
            if (! Schema::hasColumn('servers', 'oci_instance_id')) {
                $table->string('oci_instance_id')->nullable()->after('oci_connection_id');
            }
            if (! Schema::hasColumn('servers', 'oci_instance_status')) {
                $table->string('oci_instance_status')->nullable()->after('oci_instance_id');
            }
            if (! Schema::hasColumn('servers', 'oci_region')) {
                $table->string('oci_region')->nullable()->after('oci_instance_status');
            }
            if (! Schema::hasColumn('servers', 'oci_compartment_id')) {
                $table->string('oci_compartment_id')->nullable()->after('oci_region');
            }
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            foreach (['oci_compartment_id', 'oci_region', 'oci_instance_status', 'oci_instance_id'] as $col) {
                if (Schema::hasColumn('servers', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('servers', 'oci_connection_id')) {
                $table->dropForeign(['oci_connection_id']);
                $table->dropColumn('oci_connection_id');
            }
        });
    }
};
