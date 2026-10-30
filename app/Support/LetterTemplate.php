<?php

namespace App\Support;

use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Teacher;
use Carbon\CarbonInterface;

/**
 * Fills a letter template with data from SIAKAD. Placeholders are written in
 * braces, e.g. {mahasantri.nama}; unknown ones are left as typed, so a typo
 * shows up in the preview instead of disappearing.
 */
final class LetterTemplate
{
    /**
     * Placeholder => description, for the help on the letter types page.
     *
     * @return array<string, string>
     */
    public static function placeholders(): array
    {
        return [
            '{mahasantri.nama}' => __('Name of the mahasantri'),
            '{mahasantri.nim}' => __('NIM'),
            '{mahasantri.prodi}' => __('Study program'),
            '{mahasantri.semester}' => __('Class this semester, e.g. Semester III Putra'),
            '{mahasantri.angkatan}' => __('Entry year'),
            '{mahasantri.ttl}' => __('Place and date of birth'),
            '{mahasantri.alamat}' => __('Address'),
            '{muhadhir.nama}' => __('Name of the muhadhir, with title'),
            '{muhadhir.nidm}' => __('NIDM'),
            '{tahun_akademik}' => __('Active semester, e.g. 2026/2027 · Ganjil'),
            '{tanggal}' => __('Date of the letter'),
            '{keperluan}' => __('Purpose given in the request'),
            '{lembaga.nama}' => __('Institution name'),
            '{mudir.nama}' => __('Head of the institution'),
        ];
    }

    public static function fill(string $template, ?Student $student, ?Teacher $teacher, CarbonInterface $date, ?string $purpose = null): string
    {
        return strtr($template, self::values($student, $teacher, $date, $purpose));
    }

    /**
     * Placeholder => value. Placeholders of a person the letter is not about are left out.
     *
     * @return array<string, string>
     */
    public static function values(?Student $student, ?Teacher $teacher, CarbonInterface $date, ?string $purpose = null): array
    {
        $semester = Semester::active();
        $values = [
            '{tahun_akademik}' => $semester?->label() ?? '',
            '{tanggal}' => $date->translatedFormat('j F Y'),
            '{keperluan}' => $purpose ?? '...',
            '{lembaga.nama}' => (string) Setting::get('institution.name'),
            '{mudir.nama}' => (string) (Setting::get('institution.head') ?? ''),
        ];

        if ($student !== null) {
            $student->loadMissing(['user', 'studyProgram']);
            $class = $semester === null ? null : $student->classEnrollments()->where('semester_id', $semester->id)->value('class_name');

            $values += [
                '{mahasantri.nama}' => $student->user->name,
                '{mahasantri.nim}' => $student->nim,
                '{mahasantri.prodi}' => $student->studyProgram->name,
                '{mahasantri.semester}' => $class ?? '',
                '{mahasantri.angkatan}' => (string) $student->entry_year,
                '{mahasantri.ttl}' => trim(($student->birth_place ?? '').', '.($student->birth_date?->translatedFormat('j F Y') ?? ''), ', '),
                '{mahasantri.alamat}' => (string) ($student->address ?? ''),
            ];
        }

        if ($teacher !== null) {
            $teacher->loadMissing('user');
            $values += [
                '{muhadhir.nama}' => $teacher->displayName(),
                '{muhadhir.nidm}' => $teacher->nidm,
            ];
        }

        return $values;
    }
}
