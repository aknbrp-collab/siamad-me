<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letters made from a Word template (v4 D19): a letter keeps the template
 * version it was issued with, so changing the template later never changes
 * a letter already sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letters', function (Blueprint $table) {
            $table->foreignId('document_template_id')->nullable()->after('letter_type_id')->constrained()->restrictOnDelete();
            $table->index('document_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('letters', fn (Blueprint $table) => $table->dropConstrainedForeignId('document_template_id'));
    }
};
