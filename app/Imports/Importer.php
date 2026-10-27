<?php

namespace App\Imports;

use App\Support\CreatedAccount;

/**
 * One kind of bulk import (mahasantri, ustadz, subjects). Rows are validated
 * with the same rules as the manual form, then created one by one.
 */
interface Importer
{
    public function label(): string;

    /**
     * Whether each row creates a login (and the import hands back a
     * credentials sheet).
     */
    public function createsAccounts(): bool;

    /**
     * Template columns: header => description shown in the template.
     *
     * @return array<string, string>
     */
    public function columns(): array;

    /**
     * An example row for the template.
     *
     * @return array<string, string>
     */
    public function example(): array;

    /**
     * The column that must be unique within the file (the login ID).
     */
    public function keyColumn(): string;

    /**
     * Turn a spreadsheet row into the input of the create action.
     *
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    public function toInput(array $row): array;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input): array;

    /**
     * @param  array<string, mixed>  $input
     * @return CreatedAccount|null the new login, for importers that create accounts
     */
    public function create(array $input): ?CreatedAccount;
}
