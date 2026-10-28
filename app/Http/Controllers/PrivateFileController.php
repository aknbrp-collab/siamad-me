<?php

namespace App\Http\Controllers;

use App\Enums\PaymentSource;
use App\Enums\Role;
use App\Models\CashEntry;
use App\Models\IncomingLetter;
use App\Models\Material;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\Submission;
use App\Models\Teacher;
use App\Models\TeacherDocument;
use App\Support\ActiveRole;
use App\Support\OfferingAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves photos and personal documents from the private disk to their owner
 * and to Admin / Superadmin only; payment proofs to finance staff only;
 * classroom files to the people of that class.
 */
class PrivateFileController extends Controller
{
    public const KINDS = ['student-photo', 'teacher-photo', 'student-document', 'teacher-document', 'payment-proof', 'cash-proof', 'incoming-letter', 'material', 'submission'];

    public function __invoke(Request $request, string $kind, int $id): StreamedResponse
    {
        if (in_array($kind, ['material', 'submission'], true)) {
            return $this->classroomFile($request, $kind, $id);
        }

        [$path, $ownerUserId, $downloadName] = match ($kind) {
            'student-photo' => $this->photo(Student::query()->findOrFail($id)),
            'teacher-photo' => $this->photo(Teacher::query()->findOrFail($id)),
            'student-document' => $this->document(StudentDocument::query()->with('student')->findOrFail($id)),
            'teacher-document' => $this->document(TeacherDocument::query()->with('teacher')->findOrFail($id)),
            // Finance staff, plus the mahasantri / wali who uploaded it with their transfer confirmation.
            'payment-proof' => $this->paymentProof(Payment::query()->findOrFail($id)),
            // Receipts of cash book entries (expenses): finance staff only.
            'cash-proof' => [CashEntry::query()->findOrFail($id)->proof_path, null, null],
            // Scans of incoming letters: Admin and Superadmin.
            'incoming-letter' => [IncomingLetter::query()->findOrFail($id)->scan_path, null, null],
            default => abort(404),
        };

        $isStaff = in_array(ActiveRole::get(), in_array($kind, ['payment-proof', 'cash-proof'], true)
            ? [Role::Admin, Role::AdminPayment, Role::Superadmin]
            : [Role::Admin, Role::Superadmin], true);

        abort_unless($isStaff || ($ownerUserId !== null && $request->user()?->id === $ownerUserId), 403);

        return $this->serve($path, $downloadName);
    }

    /**
     * Materials: the ustadz, enrolled mahasantri and Admin. Submissions: their
     * author, the ustadz and Admin.
     */
    private function classroomFile(Request $request, string $kind, int $id): StreamedResponse
    {
        $user = $request->user();
        $isStaff = in_array(ActiveRole::get(), [Role::Admin, Role::Superadmin], true);

        if ($kind === 'material') {
            $material = Material::query()->with('courseOffering')->findOrFail($id);
            abort_unless($isStaff || OfferingAccess::participates($user, $material->courseOffering), 403);

            return $this->serve($material->file_path, $material->original_name);
        }

        $submission = Submission::query()->with(['assignment.courseOffering', 'courseEnrollment.student'])->findOrFail($id);
        $allowed = $isStaff
            || OfferingAccess::teaches($user, $submission->assignment->courseOffering)
            || $submission->courseEnrollment->student->user_id === $user?->id;
        abort_unless($allowed, 403);

        return $this->serve($submission->file_path, $submission->original_name);
    }

    private function serve(?string $path, ?string $downloadName): StreamedResponse
    {
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $downloadName, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{0: string|null, 1: int|null, 2: string|null}
     */
    private function paymentProof(Payment $payment): array
    {
        return [$payment->proof_path, $payment->source === PaymentSource::Payer ? $payment->recorded_by : null, null];
    }

    /**
     * @return array{0: string|null, 1: int, 2: string|null}
     */
    private function photo(Student|Teacher $person): array
    {
        return [$person->photo_path, $person->user_id, null];
    }

    /**
     * @return array{0: string|null, 1: int, 2: string|null}
     */
    private function document(StudentDocument|TeacherDocument $document): array
    {
        $person = $document instanceof StudentDocument ? $document->student : $document->teacher;

        return [$document->file_path, $person->user_id, $document->original_name];
    }
}
