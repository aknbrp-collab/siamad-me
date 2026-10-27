@php($money = fn (int $amount) => \App\Support\Money::format($amount))
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Kas</title>
    @include('records.style')
</head>
<body>
    @include('records.letterhead')

    <h2>LAPORAN KAS</h2>
    <p class="center muted" style="margin-top: -6px">{{ $from->translatedFormat('F Y') }}@if (! $from->isSameMonth($to)) – {{ $to->translatedFormat('F Y') }}@endif</p>

    <h3>Pemasukan</h3>
    <table class="grades">
        <tbody>
            <tr><td>Pembayaran tagihan mahasantri</td><td class="num">{{ $money($summary['tuition']) }}</td></tr>
            @foreach ($summary['income'] as $name => $amount)
                <tr><td>{{ $name }}</td><td class="num">{{ $money($amount) }}</td></tr>
            @endforeach
            <tr><th>Jumlah pemasukan</th><th class="num">{{ $money($summary['total_in']) }}</th></tr>
        </tbody>
    </table>

    <h3>Pengeluaran</h3>
    <table class="grades">
        <tbody>
            @forelse ($summary['expense'] as $name => $amount)
                <tr><td>{{ $name }}</td><td class="num">{{ $money($amount) }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">Tidak ada pengeluaran.</td></tr>
            @endforelse
            <tr><th>Jumlah pengeluaran</th><th class="num">{{ $money($summary['total_out']) }}</th></tr>
        </tbody>
    </table>

    <table class="grades" style="margin-top: 10px">
        <tbody>
            <tr><th>Surplus / (defisit)</th><th class="num">{{ $money($summary['total_in'] - $summary['total_out']) }}</th></tr>
        </tbody>
    </table>

    <h3>Saldo per {{ $to->translatedFormat('j F Y') }}</h3>
    <table class="grades">
        <tbody>
            @foreach ($balances as $row)
                <tr><td>{{ $row['account']->label() }}</td><td class="num">{{ $money($row['balance']) }}</td></tr>
            @endforeach
            <tr><th>Jumlah saldo</th><th class="num">{{ $money((int) $balances->sum('balance')) }}</th></tr>
        </tbody>
    </table>

    @if ($summary['unassigned'] > 0)
        <p class="muted">Catatan: {{ $money($summary['unassigned']) }} pembayaran tagihan pada periode ini belum dibukukan ke rekening mana pun dan tidak termasuk dalam saldo.</p>
    @endif

    @include('records.signature')
</body>
</html>
