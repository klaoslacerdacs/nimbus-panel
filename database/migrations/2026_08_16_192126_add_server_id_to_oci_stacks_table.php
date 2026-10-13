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
            $table->foreignId('server_id')->nullable()->after('oci_connection_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oci_stacks', function (Blueprint $table) {
            $table->dropForeign(['server_id']);
            $table->dropColumn('server_id');
        });
    }
};
