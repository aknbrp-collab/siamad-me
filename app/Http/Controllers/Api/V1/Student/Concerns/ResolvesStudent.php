<?php

namespace App\Http\Controllers\Api\V1\Student\Concerns;

use App\Models\CourseEnrollment;
use App\Models\CourseOffering;
use App\Models\Student;
use App\Support\OfferingAccess;
use Illuminate\Http\Request;

/**
 * The signed-in mahasantri, and their enrolment in a class kuliah.
 */
trait ResolvesStudent
{
    protected function student(Request $request): Student
    {
        $student = $request->user()->student;
        abort_if($student === null, 403);

        return $student;
    }

    protected function enrollment(Request $request, CourseOffering $offering): CourseEnrollment
    {
        $enrollment = OfferingAccess::enrollment($request->user(), $offering);
        abort_if($enrollment === null, 403);

        return $enrollment;
    }
}
