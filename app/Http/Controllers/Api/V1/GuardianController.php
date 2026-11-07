<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BillStatus;
use App\Http\Controllers\Controller;
use App\Models\ClassEnrollment;
use App\Models\Semester;
use App\Models\Student;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Wali santri', weight: 10)]
class GuardianController extends Controller
{
    /**
     * My children.
     *
     * The wali santri's children with their class this semester and what they still owe. Use each `id` with
     * `/students/{id}/khs`, `/transcript`, `/bills` and `/activities`.
     */
    public function children(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;
        abort_if($guardian === null, 403);

        $children = $guardian->students()->with(['user', 'bills' => fn ($query) => $query->whereIn('status', [BillStatus::Unpaid, BillStatus::Partial])])->orderBy('nim')->get();
        $classes = ClassEnrollment::query()->where('semester_id', Semester::active()?->id)->whereIn('student_id', $children->modelKeys())->pluck('class_name', 'student_id');

        return response()->json(['data' => $children->map(fn (Student $child) => [
            'id' => $child->id,
            'nim' => $child->nim,
            'name' => $child->user->name,
            'class_name' => $classes[$child->id] ?? null,
            'status' => $child->status->value,
            'outstanding' => (int) $child->bills->sum(fn ($bill) => $bill->net_amount - $bill->paid_amount),
            'photo_url' => $child->photo_path === null ? null : ApiFiles::url($request->user(), ActiveRole::get(), 'student-photo', $child->id),
        ])->values()->all()]);
    }
}
