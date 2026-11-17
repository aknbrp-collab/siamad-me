<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\ActiveRole;
use App\Support\PrintMarks;
use App\Support\PrintTemplates;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReceiptController extends Controller
{
    /**
     * The official receipt of a verified payment, or a provisional slip
     * (clearly marked) while it awaits verification. Finance staff, the
     * mahasantri and their wali santri may open it.
     */
    public function __invoke(Request $request, Payment $payment): Response|BinaryFileResponse
    {
        abort_if($payment->status === PaymentStatus::Rejected, 404);

        $payment->load(['bill.student.user', 'bill.student.guardians', 'bill.semester.academicYear', 'recorder', 'verifier', 'allocations.line']);
        $student = $payment->bill->student;
        $user = $request->user();

        $allowed = in_array(ActiveRole::get(), [Role::Admin, Role::AdminPayment, Role::Superadmin], true)
            || $student->user_id === $user->id
            || $student->guardians->contains('user_id', $user->id);

        abort_unless($allowed, 403);

        $marks = PrintMarks::for(PrintDocument::Receipt, $request);

        // Only the official receipt carries the signature and stamp images.
        if ($payment->status !== PaymentStatus::Verified) {
            $marks = new PrintMarks($marks->signatory, $marks->stamp, false);
        }

        $name = str_replace('/', '-', $payment->receipt_number ?? 'slip-'.$payment->id);

        // The official receipt from the Word template when one is uploaded (v7 D44); a slip awaiting
        // verification or a voided payment keeps the built-in layout with its watermark.
        if ($payment->status === PaymentStatus::Verified) {
            $word = PrintTemplates::respond(PrintDocument::Receipt, PrintTemplates::receipt($payment, Setting::get('receipt.footer')), $marks, $name, $request->query('format') === 'docx');
            if ($word !== null) {
                return $word;
            }
        }

        $pdf = Pdf::loadView('receipts.payment', [
            'payment' => $payment,
            'document' => PrintDocument::Receipt,
            'marks' => $marks,
            'footer' => Setting::get('receipt.footer'),
        ])->setPaper('a5', 'landscape');

        return $pdf->stream($name.'.pdf');
    }
}
