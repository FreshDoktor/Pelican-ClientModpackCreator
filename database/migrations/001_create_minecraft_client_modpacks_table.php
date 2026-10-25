<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minecraft_client_modpacks', function (Blueprint $table) {
            $table->increments('id');

            // A server has one client modpack, every build replaces the file.
            $table->unsignedInteger('server_id')->unique();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            // Secret part of the download link. It stays the same across builds, so it can be shared once.
            $table->string('link_token', 40)->unique();

            // Settings, empty values are detected or filled in at build time.
            $table->string('name')->nullable();
            $table->string('version')->nullable();
            $table->text('summary')->nullable();
            $table->string('minecraft_version')->nullable();
            $table->string('loader')->nullable();
            $table->string('loader_version')->nullable();
            $table->boolean('embed_unknown')->default(true);
            $table->json('include_paths')->nullable();
            $table->json('mod_rules')->nullable();
            $table->json('extra_mods')->nullable();

            // The last build. file_token names the file on the panel's disk, a new one with every build.
            $table->string('file_token', 40)->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->char('sha1', 40)->nullable();
            $table->string('built_version')->nullable();
            $table->string('built_minecraft_version')->nullable();
            $table->string('built_loader')->nullable();
            $table->string('built_loader_version')->nullable();
            $table->json('contents')->nullable();
            $table->json('warnings')->nullable();
            // The mods folder at build time, to tell when the modpack is outdated.
            $table->string('mods_fingerprint', 40)->nullable();
            $table->timestamp('built_at')->nullable();
            $table->unsignedInteger('built_by')->nullable();
            $table->foreign('built_by')->references('id')->on('users')->nullOnDelete();
            // Last change of a setting that only takes effect with the next build.
            $table->timestamp('settings_changed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minecraft_client_modpacks');
    }
};
