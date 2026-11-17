<?php

namespace App\Http\Controllers;

use App\Enums\PrintDocument;
use App\Models\CashAccount;
use App\Support\CashBook;
use App\Support\PrintMarks;
use App\Support\PrintTemplates;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The cash report PDF (Admin Keuangan, Superadmin): income and expense by
 * category for a range of months, and each account's balance at its end.
 */
class CashReportController extends Controller
{
    public function __invoke(Request $request): Response|BinaryFileResponse
    {
        $month = fn (string $key, string $fallback) => preg_match('/^\d{4}-\d{2}$/', (string) $request->query($key)) ? (string) $request->query($key) : $fallback;

        $from = Carbon::parse($month('from', now()->format('Y-m')).'-01')->startOfMonth();
        $to = Carbon::parse($month('to', now()->format('Y-m')).'-01')->endOfMonth();
        abort_if($to->lt($from), 422);

        $after = $to->copy()->addDay()->startOfDay();
        $balances = CashAccount::query()->orderByDesc('type')->orderBy('name')->get()
            ->filter(fn (CashAccount $account) => $account->opening_date->lt($after))
            ->map(fn (CashAccount $account) => ['account' => $account, 'balance' => CashBook::balance($account, $after)])
            ->values();

        $summary = CashBook::summary($from, $to);
        $marks = PrintMarks::for(PrintDocument::CashReport, $request);
        $name = 'Laporan-Kas-'.$from->format('Y-m').'-'.$to->format('Y-m');

        // From the Word template when one is uploaded (v7 D44).
        $word = PrintTemplates::respond(PrintDocument::CashReport, PrintTemplates::cashReport($from, $to, $summary, $balances), $marks, $name, $request->query('format') === 'docx');
        if ($word !== null) {
            return $word;
        }

        return Pdf::loadView('records.cash-report', [
            'from' => $from,
            'to' => $to,
            'summary' => $summary,
            'balances' => $balances,
            'document' => PrintDocument::CashReport,
            'marks' => $marks,
            'date' => now(),
        ])->setPaper('a4')->stream($name.'.pdf');
    }
}
