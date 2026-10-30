<?php

namespace App\Http\Controllers;

use App\Support\Templates;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportTemplateController extends Controller
{
    /**
     * The import template in use: a replacement uploaded on the Templates
     * page, or the designed default (headers with required ones marked *, an
     * example row and an instructions sheet).
     */
    public function __invoke(string $type): BinaryFileResponse
    {
        return Templates::download("import.{$type}");
    }
}
