<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\AcademicRecordPdfController;
use App\Http\Controllers\Api\V1\Concerns\ActsForSignedUser;
use App\Http\Controllers\CashReportController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DispensationLetterController;
use App\Http\Controllers\ExamCardController;
use App\Http\Controllers\LetterPdfController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ScoreTemplateController;
use App\Http\Controllers\TeachingJournalController;
use App\Http\Controllers\TemplateController;
use App\Models\CourseOffering;
use App\Models\DispensationRequest;
use App\Models\ExamPeriod;
use App\Models\Letter;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\Student;
use App\Support\Templates;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a signed download link made by ApiFiles::download() (v6 D41: the API
 * does not print; PDFs and templates are the same files as on the web). The
 * web controller makes the file and checks access, as the person and role in
 * the link.
 */
#[Group('Files', weight: 6)]
class DownloadController extends Controller
{
    use ActsForSignedUser;

    /**
     * Document => [controller, method, [parameter => model class, a list of allowed values, 'month' (YYYY-MM)
     * or 'template' (a template slot key)]].
     *
     * @var array<string, array{0: class-string, 1: string, 2: array<string, class-string<Model>|list<string>|'month'|'template'>}>
     */
    public const DOCUMENTS = [
        'khs' => [AcademicRecordPdfController::class, 'khs', ['student' => Student::class, 'semester' => Semester::class]],
        'transcript' => [AcademicRecordPdfController::class, 'transcript', ['student' => Student::class]],
        'exam-card' => [ExamCardController::class, '__invoke', ['period' => ExamPeriod::class, 'student' => Student::class]],
        'dispensation-letter' => [DispensationLetterController::class, '__invoke', ['dispensation' => DispensationRequest::class]],
        'letter' => [LetterPdfController::class, '__invoke', ['letter' => Letter::class]],
        'receipt' => [ReceiptController::class, '__invoke', ['payment' => Payment::class]],
        // Muhadhir (Step 7): the score import template, the RPS / journal Word templates and the journal PDF.
        'score-template' => [ScoreTemplateController::class, '__invoke', ['offering' => CourseOffering::class]],
        'teaching-template' => [TemplateController::class, 'teaching', ['offering' => CourseOffering::class, 'kind' => ['rps', 'journal']]],
        'teaching-journal' => [TeachingJournalController::class, '__invoke', ['offering' => CourseOffering::class]],
        // Finance (Step 10): the cash report of a range of months.
        'cash-report' => [CashReportController::class, '__invoke', ['from' => 'month', 'to' => 'month']],
        // Managed templates (Step 11): the current file of a slot (Excel or Word).
        'template' => [TemplateController::class, 'show', ['key' => 'template']],
    ];

    /**
     * Download a file made on request.
     *
     * KHS, transcript, exam card, letters, receipts, the cash report, and for muhadhir the score template,
     * RPS / journal templates and the journal PDF, through the signed links in other responses (`pdf_url`, `card_url`,
     * `receipt_url`, `…_template_url`); they expire after a few minutes.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request, string $document): Response
    {
        abort_unless(array_key_exists($document, self::DOCUMENTS), 404);
        $this->actForSignedUser($request);

        [$controller, $method, $arguments] = self::DOCUMENTS[$document];
        $parameters = ['request' => $request];

        foreach ($arguments as $name => $type) {
            if ($type === 'month') {
                abort_unless(preg_match('/^\d{4}-\d{2}$/', (string) $request->query($name)) === 1, 404);

                continue;
            }

            if ($type === 'template') {
                $value = (string) $request->query($name);
                abort_unless(array_key_exists($value, Templates::slots()), 404);
                $parameters[$name] = $value;

                continue;
            }

            if (is_array($type)) {
                $value = (string) $request->query($name);
                abort_unless(in_array($value, $type, true), 404);
                $parameters[$name] = $value;
            } else {
                $parameters[$name] = $type::query()->findOrFail($request->integer($name));
            }
        }

        $action = [app($controller), $method];
        abort_unless(is_callable($action), 500);

        $response = app()->call($action, $parameters);
        abort_unless($response instanceof Response, 500);

        return $response;
    }
}
