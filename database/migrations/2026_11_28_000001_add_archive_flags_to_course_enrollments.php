<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grades imported from the archive (after-review D54): whether a grade came from the previous-year data
 * import, and whether the archive gave its letter. Grades imported with only a score can be brought in line
 * with the current grade scale; letters from the archive, and grades entered in classes, are never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->boolean('from_archive')->default(false)->after('grade_note');
            $table->boolean('archive_letter')->default(false)->after('from_archive');
        });

        // Existing imports, recognised by their note (English or Indonesian).
        $scale = DB::table('grade_scales')->orderByDesc('min_score')->get(['letter', 'min_score']);
        $letterFor = fn (float $score) => $scale->first(fn ($band) => $score >= (float) $band->min_score)->letter ?? 'E';

        DB::table('course_enrollments')
            ->where(fn ($query) => $query->where('grade_note', 'like', 'Imported from the archive of %')->orWhere('grade_note', 'like', 'Diimpor dari arsip %'))
            ->orderBy('id')
            ->each(function (object $row) use ($scale, $letterFor) {
                // A letter the archive gave: no score, or a letter of the scale other than the score's.
                $given = $row->final_score === null
                    || ($scale->contains('letter', $row->final_letter) && $row->final_letter !== $letterFor((float) $row->final_score));

                DB::table('course_enrollments')->where('id', $row->id)->update(['from_archive' => true, 'archive_letter' => $given]);
            });
    }

    public function down(): void
    {
        Schema::table('course_enrollments', function (Blueprint $table) {
            $table->dropColumn(['from_archive', 'archive_letter']);
        });
    }
};
