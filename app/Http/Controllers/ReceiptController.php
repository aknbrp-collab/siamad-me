<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Enums\PrintDocument;
use App\Enums\Role;
use App\Models\Payment;
use App\Models\Setting;
use App\Support\ActiveRole;
use App\Support\PrintMarks;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReceiptController extends Controller
{
    /**
     * The official receipt of a verified payment, or a provisional slip
     * (clearly marked) while it awaits verification. Finance staff, the
     * mahasantri and their wali santri may open it.
     */
    public function __invoke(Request $request, Payment $payment): Response
    {
        abort_if($payment->status === PaymentStatus::Rejected, 404);

        $payment->load(['bill.student.user', 'bill.student.guardians', 'bill.semester.academicYear', 'recorder', 'verifier']);
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

        $pdf = Pdf::loadView('receipts.payment', [
            'payment' => $payment,
            'document' => PrintDocument::Receipt,
            'marks' => $marks,
            'footer' => Setting::get('receipt.footer'),
        ])->setPaper('a5', 'landscape');

        $name = str_replace('/', '-', $payment->receipt_number ?? 'slip-'.$payment->id).'.pdf';

        return $pdf->stream($name);
    }
}
