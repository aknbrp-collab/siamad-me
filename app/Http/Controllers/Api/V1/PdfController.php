<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\AcademicRecordPdfController;
use App\Http\Controllers\Api\V1\Concerns\ActsForSignedUser;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ExamCardController;
use App\Http\Controllers\LetterPdfController;
use App\Http\Controllers\ReceiptController;
use App\Models\ExamPeriod;
use App\Models\Letter;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\Student;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a signed PDF link made by ApiFiles::pdf() (v6 D41: the API does not
 * print; PDFs are the same ones as on the web). The web controller makes the
 * PDF and checks access, as the person and role in the link.
 */
#[Group('Files', weight: 6)]
class PdfController extends Controller
{
    use ActsForSignedUser;

    /**
     * Document => [controller, method, [parameter => model]].
     *
     * @var array<string, array{0: class-string, 1: string, 2: array<string, class-string<Model>>}>
     */
    public const DOCUMENTS = [
        'khs' => [AcademicRecordPdfController::class, 'khs', ['student' => Student::class, 'semester' => Semester::class]],
        'transcript' => [AcademicRecordPdfController::class, 'transcript', ['student' => Student::class]],
        'exam-card' => [ExamCardController::class, '__invoke', ['period' => ExamPeriod::class, 'student' => Student::class]],
        'letter' => [LetterPdfController::class, '__invoke', ['letter' => Letter::class]],
        'receipt' => [ReceiptController::class, '__invoke', ['payment' => Payment::class]],
    ];

    /**
     * Download a PDF.
     *
     * KHS, transcript, exam card, letters and receipts, through the signed links in other responses
     * (`pdf_url`, `card_url`, `receipt_url`); they expire after a few minutes.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, string $document): Response
    {
        abort_unless(array_key_exists($document, self::DOCUMENTS), 404);
        $this->actForSignedUser($request);

        [$controller, $method, $models] = self::DOCUMENTS[$document];
        $parameters = ['request' => $request];

        foreach ($models as $name => $model) {
            $parameters[$name] = $model::query()->findOrFail($request->integer($name));
        }

        $action = [app($controller), $method];
        abort_unless(is_callable($action), 500);

        $response = app()->call($action, $parameters);
        abort_unless($response instanceof Response, 500);

        return $response;
    }
}
