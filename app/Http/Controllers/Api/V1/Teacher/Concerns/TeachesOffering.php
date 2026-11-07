<?php

namespace App\Http\Controllers\Api\V1\Teacher\Concerns;

use App\Models\CourseOffering;
use App\Models\Teacher;
use App\Support\OfferingAccess;
use Illuminate\Http\Request;

/**
 * The signed-in muhadhir, and the check that they teach a class kuliah.
 */
trait TeachesOffering
{
    protected function teacher(Request $request): Teacher
    {
        $teacher = $request->user()->teacher;
        abort_if($teacher === null, 403);

        return $teacher;
    }

    protected function authorizeOffering(Request $request, CourseOffering $offering): void
    {
        abort_unless(OfferingAccess::teaches($request->user(), $offering), 403);
    }
}
