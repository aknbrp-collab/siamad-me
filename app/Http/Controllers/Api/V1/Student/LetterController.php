<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Actions\Letters\ManageLetters;
use App\Http\Controllers\Api\V1\Student\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Models\Letter;
use App\Models\LetterRequest;
use App\Models\LetterType;
use App\Support\ActiveRole;
use App\Support\ApiFiles;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Mahasantri', weight: 9)]
class LetterController extends Controller
{
    use ResolvesStudent;

    /**
     * My letters.
     *
     * Letter types I can ask for, my requests and the letters issued to me (PDF links).
     */
    public function index(Request $request): JsonResponse
    {
        $student = $this->student($request);
        $user = $request->user();
        $role = ActiveRole::get();

        return response()->json(['data' => [
            'requestable_types' => LetterType::query()->where('is_active', true)->where('requestable', true)->orderBy('name')->get()
                ->map(fn (LetterType $type) => ['id' => $type->id, 'name' => $type->name])->values()->all(),
            'requests' => LetterRequest::query()->with(['type', 'letter'])->where('student_id', $student->id)->latest('id')->get()
                ->map(fn (LetterRequest $item) => [
                    'id' => $item->id,
                    'type' => $item->type->name,
                    'purpose' => $item->purpose,
                    'status' => $item->status->value,
                    'status_label' => $item->status->label(),
                    'reject_reason' => $item->reject_reason,
                    'created_at' => $item->created_at->toIso8601String(),
                    'letter_id' => $item->letter?->id,
                ])->values()->all(),
            'letters' => Letter::query()->with('type')->where('student_id', $student->id)->whereNull('cancelled_at')->orderByDesc('issued_on')->orderByDesc('id')->get()
                ->map(fn (Letter $letter) => [
                    'id' => $letter->id,
                    'number' => $letter->number,
                    'type' => $letter->type->name,
                    'subject_line' => $letter->subject_line,
                    'issued_on' => $letter->issued_on->toDateString(),
                    'pdf_url' => ApiFiles::pdf($user, $role, 'letter', ['letter' => $letter->id]),
                ])->values()->all(),
        ]]);
    }

    /**
     * Request a letter.
     *
     * The academic office issues it; a notification follows when it is ready.
     */
    public function store(Request $request, ManageLetters $letters): JsonResponse
    {
        $request->validate([
            'letter_type_id' => ['required', 'integer'],
            /** What the letter is for, e.g. "Pengajuan beasiswa". */
            'purpose' => ['required', 'string', 'max:500'],
        ]);

        $item = $letters->request($this->student($request), $request->integer('letter_type_id'), (string) $request->input('purpose'));

        return response()->json(['data' => ['id' => $item->id, 'status' => $item->status->value]], 201);
    }
}
