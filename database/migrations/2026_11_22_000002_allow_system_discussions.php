<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The discussion of a Tanya Hukum question is opened by SIAKAD when the CMS
 * forwards it, not by a person (v5 D30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discussions', fn (Blueprint $table) => $table->foreignId('created_by')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('discussions', fn (Blueprint $table) => $table->foreignId('created_by')->nullable(false)->change());
    }
};
