<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates managed by staff (v4 D24). Every upload of a slot is a new
 * version; a version without a file means "back to the built-in default".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50);
            $table->unsignedInteger('version');
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
