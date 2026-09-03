<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('embed_data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_templates');
    }
};
