<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ijazah details (v4 D22): level, institution, graduation year and, for
 * muhadhir, the gelar. Muhadhir may have several ijazah (one per level), so
 * "one document per type" now holds for KTP and KK only, and ijazah are
 * unique per level.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['student', 'teacher'] as $owner) {
            Schema::table("{$owner}_documents", function (Blueprint $table) use ($owner) {
                $table->string('education_level', 20)->nullable()->after('type');
                $table->string('institution', 150)->nullable()->after('education_level');
                $table->unsignedSmallInteger('graduation_year')->nullable()->after('institution');
                $table->string('degree_title', 50)->nullable()->after('graduation_year');
                $table->dropUnique(["{$owner}_id", 'type']);
                // The unique index also served the foreign key; partial indexes do not.
                $table->index("{$owner}_id");
            });

            DB::statement("create unique index {$owner}_documents_one_per_type on {$owner}_documents ({$owner}_id, type) where type <> 'ijazah'");
            DB::statement("create unique index {$owner}_documents_ijazah_per_level on {$owner}_documents ({$owner}_id, education_level) where type = 'ijazah'");
        }
    }

    public function down(): void
    {
        foreach (['student', 'teacher'] as $owner) {
            DB::statement("drop index {$owner}_documents_one_per_type");
            DB::statement("drop index {$owner}_documents_ijazah_per_level");

            Schema::table("{$owner}_documents", function (Blueprint $table) use ($owner) {
                $table->dropIndex(["{$owner}_id"]);
                $table->dropColumn(['education_level', 'institution', 'graduation_year', 'degree_title']);
                $table->unique(["{$owner}_id", 'type']);
            });
        }
    }
};
