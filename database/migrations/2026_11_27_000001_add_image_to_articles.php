<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional picture for an article (v7 D48): on the working copy, and kept with each published version
 * (the files are never overwritten, so an older version keeps its picture). Sent to the CMS as image_url.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['articles', 'article_versions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('image_path')->nullable()->after('keywords');
                $table->string('image_caption', 255)->nullable()->after('image_path');
            });
        }
    }

    public function down(): void
    {
        foreach (['articles', 'article_versions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['image_path', 'image_caption']);
            });
        }
    }
};
