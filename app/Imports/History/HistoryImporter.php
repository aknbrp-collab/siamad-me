<?php

namespace App\Imports\History;

use App\Models\Semester;
use App\Models\User;

/**
 * Previous-year data for one closed semester (v3 Step 3), imported while
 * maintenance mode is on. Unlike the account imports, every row is checked
 * against the semester and existing records are never overwritten.
 */
interface HistoryImporter
{
    public function label(): string;

    /**
     * Whether rows belong to one closed semester chosen on the page (class
     * placements, grades, bills) or carry their own date (cash entries).
     */
    public function needsSemester(): bool;

    /**
     * Template columns: header => description.
     *
     * @return array<string, string>
     */
    public function columns(): array;

    /**
     * @return array<string, string>
     */
    public function example(): array;

    /**
     * Rows with the same key in one file are duplicates.
     *
     * @param  array<string, string>  $row
     */
    public function key(array $row): string;

    /**
     * Check one row. "input" is what import() needs; "errors" skip the row.
     * $semester is set exactly when needsSemester() is true.
     *
     * @param  array<string, string>  $row
     * @return array{input: array<string, mixed>, errors: list<string>}
     */
    public function check(?Semester $semester, array $row): array;

    /**
     * @param  array<string, mixed>  $input
     */
    public function import(?Semester $semester, array $input, User $by): void;
}
