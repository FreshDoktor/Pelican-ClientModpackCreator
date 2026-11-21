<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minecraft_client_modpacks', function (Blueprint $table) {
            // Put the server into the players' server list (servers.dat). Empty name and address are filled in at build time.
            $table->boolean('server_list')->default(false)->after('extra_mods');
            $table->string('server_list_name')->nullable()->after('server_list');
            $table->string('server_list_address')->nullable()->after('server_list_name');
        });
    }

    public function down(): void
    {
        Schema::table('minecraft_client_modpacks', function (Blueprint $table) {
            $table->dropColumn(['server_list', 'server_list_name', 'server_list_address']);
        });
    }
};
