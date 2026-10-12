<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of the firewall rules a job applied: the version history used for rollbacks.
     */
    public function up(): void
    {
        Schema::table('oci_stack_jobs', function (Blueprint $table) {
            $table->json('firewall_rules')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('oci_stack_jobs', function (Blueprint $table) {
            $table->dropColumn('firewall_rules');
        });
    }
};
