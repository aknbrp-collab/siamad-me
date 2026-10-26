<?php

namespace App\Http\Controllers;

use App\Actions\Teaching\ImportScores;
use App\Models\CourseOffering;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ScoreTemplateController extends Controller
{
    /**
     * The score sheet of one of the ustadz's own offerings, pre-filled with
     * the enrolled mahasantri and any scores already entered.
     */
    public function __invoke(Request $request, CourseOffering $offering, ImportScores $import): BinaryFileResponse
    {
        abort_unless($offering->teacher()->value('user_id') === $request->user()->id, 403);

        return $import->template($offering);
    }
}
