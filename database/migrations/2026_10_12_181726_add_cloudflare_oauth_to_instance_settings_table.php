<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nimbus: OAuth client this installation registered in its Cloudflare account ("Connect Cloudflare").
     */
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('cloudflare_oauth_client_id')->nullable();
            $table->text('cloudflare_oauth_client_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['cloudflare_oauth_client_id', 'cloudflare_oauth_client_secret']);
        });
    }
};
