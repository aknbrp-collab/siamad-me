@php
    use App\Enums\PaymentStatus;
    use App\Support\Money;

    $bill = $payment->bill;
    $watermark = match ($payment->status) {
        PaymentStatus::Unverified => 'BELUM TERVERIFIKASI',
        PaymentStatus::Void => 'BATAL',
        default => null,
    };
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $payment->receipt_number ?? 'Slip pembayaran' }}</title>
    <style>
        @page { margin: 24px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #18181b; }
        .title { font-size: 14px; font-weight: bold; letter-spacing: 1px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        td.label { width: 34%; color: #52525b; }
        .amount { font-size: 18px; font-weight: bold; }
        .words { font-style: italic; text-transform: capitalize; }
        .notice { margin-top: 10px; padding: 6px 8px; border: 1px solid #d97706; color: #92400e; }
        .footer { clear: both; padding-top: 18px; color: #71717a; font-size: 9px; }
        .watermark { position: fixed; top: 38%; left: 8%; width: 84%; text-align: center; font-size: 46px;
            font-weight: bold; color: rgba(220, 38, 38, 0.16); transform: rotate(-18deg); }
    </style>
</head>
<body>
    @if ($watermark)
        <div class="watermark">{{ $watermark }}</div>
    @endif

    @include('records.letterhead')

    <div class="title">{{ $payment->status === PaymentStatus::Unverified ? 'SLIP PEMBAYARAN (SEMENTARA)' : 'KWITANSI PEMBAYARAN' }}</div>

    <table>
        <tr><td class="label">No. kwitansi</td><td>{{ $payment->receipt_number ?? '—' }}</td></tr>
        <tr><td class="label">Telah terima dari</td><td>{{ $bill->student->user->name }} (NIM {{ $bill->student->nim }})</td></tr>
        <tr><td class="label">Untuk pembayaran</td><td>Tagihan {{ $bill->number }} · {{ $bill->semester->label() }}</td></tr>
        <tr><td class="label">Sejumlah</td><td class="amount">{{ Money::format($payment->amount) }}</td></tr>
        <tr><td class="label">Terbilang</td><td class="words">{{ Money::inWords($payment->amount) }}</td></tr>
        <tr><td class="label">Cara bayar</td><td>{{ $payment->method->label() }}@if ($payment->reference) · {{ $payment->reference }}@endif · {{ $payment->paid_on->translatedFormat('j F Y') }}</td></tr>
        <tr><td class="label">Sisa tagihan</td><td>{{ Money::format($bill->outstanding()) }}</td></tr>
    </table>

    @if ($payment->status === PaymentStatus::Unverified)
        <div class="notice">Pembayaran ini dicatat oleh {{ $payment->recorder->name }} dan belum diverifikasi. Kwitansi resmi terbit setelah verifikasi.</div>
    @elseif ($payment->status === PaymentStatus::Void)
        <div class="notice">Pembayaran ini dibatalkan: {{ $payment->status_note }}</div>
    @endif

    @include('records.signature', [
        'date' => $payment->verified_at ?? $payment->created_at,
        'note' => ($payment->status === PaymentStatus::Unverified ? 'Dicatat oleh ' : 'Diverifikasi oleh ').($payment->verifier ?? $payment->recorder)->name,
    ])

    <div class="footer">{{ $footer }}</div>
</body>
</html>
