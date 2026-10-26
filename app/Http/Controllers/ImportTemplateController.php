<?php

namespace App\Http\Controllers;

use App\Imports\Importers;
use App\Support\SpreadsheetDownload;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportTemplateController extends Controller
{
    /**
     * An .xlsx with the expected headers (required ones marked *) and one
     * example row. Column descriptions are shown on the import page.
     */
    public function __invoke(string $type): BinaryFileResponse
    {
        $importer = Importers::make($type);

        $header = fn (string $column) => $column.(str_starts_with($importer->columns()[$column], __('Required')) ? '*' : '');

        $rows = [
            array_combine(array_map($header, array_keys($importer->columns())), array_values($importer->example())),
        ];

        return SpreadsheetDownload::make("template-{$type}.xlsx", $rows);
    }
}
