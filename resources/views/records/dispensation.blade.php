<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Permohonan Dispensasi {{ $student->nim }}</title>
    @include('records.style')
    <style>
        p { margin: 4px 0; line-height: 1.45; }
        .reason { margin-top: 4px; padding: 6px 8px; border: 1px solid #a1a1aa; white-space: pre-line; line-height: 1.45; }
        table.grades tfoot td { font-weight: bold; background: #f4f4f5; }
        .signs { width: 100%; margin-top: 18px; border-collapse: collapse; }
        .signs td { width: 50%; text-align: center; vertical-align: top; }
        .signs .space { height: 64px; }
        .signs .name { font-weight: bold; text-decoration: underline; }
    </style>
</head>
<body>
    @include('records.letterhead')

    <h2>SURAT PERMOHONAN DISPENSASI</h2>

    <p>
        Kepada Yth.<br>
        {{ $signatory?->position ?? 'Mudir' }} {{ $institution }}<br>
        di tempat
    </p>

    <p style="margin-top: 10px">Assalamu'alaikum warahmatullahi wabarakatuh.</p>
    <p>Yang bertanda tangan di bawah ini:</p>

    <table class="identity" style="margin-left: 12px">
        <tr><td>Nama</td><td>: {{ $student->user->name }}</td></tr>
        <tr><td>NIM</td><td>: {{ $student->nim }}</td></tr>
        <tr><td>Program studi</td><td>: {{ $student->studyProgram->name }}</td></tr>
        <tr><td>Kelas</td><td>: {{ $className ?? $student->gender->classLabel() }}</td></tr>
        <tr><td>Semester</td><td>: {{ $request->semester->label() }}</td></tr>
        @if ($guardians !== '')
            <tr><td>Wali santri</td><td>: {{ $guardians }}</td></tr>
        @endif
        @if ($student->phone)
            <tr><td>No. HP</td><td>: {{ $student->phone }}</td></tr>
        @endif
    </table>

    <p style="margin-top: 8px">
        mengajukan permohonan dispensasi atas administrasi (tagihan) yang belum dapat saya selesaikan, agar tetap
        dapat mengikuti kegiatan akademik dan ujian {{ $request->semester->label() }}. Rincian tagihan saya:
    </p>

    <table class="grades">
        <thead>
            <tr>
                <th>Semester</th>
                <th>No. tagihan</th>
                <th class="num">Tagihan</th>
                <th class="num">Sudah dibayar</th>
                <th class="num">Sisa</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($bills as $bill)
                <tr>
                    <td>{{ $bill->semester->label() }}</td>
                    <td>{{ $bill->number }}</td>
                    <td class="num">{{ \App\Support\Money::format($bill->net_amount) }}</td>
                    <td class="num">{{ \App\Support\Money::format($bill->paid_amount) }}@if ($bill->verified_payments_count) <span class="muted">({{ $bill->verified_payments_count }}×)</span>@endif</td>
                    <td class="num">{{ \App\Support\Money::format($bill->outstanding()) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="center muted">Tidak ada tagihan yang tersisa.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">Jumlah yang belum dibayar</td>
                <td class="num">{{ \App\Support\Money::format($bills->sum(fn ($bill) => $bill->outstanding())) }}</td>
            </tr>
        </tfoot>
    </table>

    <h3>Alasan</h3>
    <div class="reason">{{ $request->reason }}</div>

    <p style="margin-top: 10px">
        Demikian permohonan ini saya sampaikan. Atas perhatian dan kebijaksanaan {{ $signatory?->position ?? 'Mudir' }},
        saya ucapkan terima kasih.
    </p>
    <p>Wassalamu'alaikum warahmatullahi wabarakatuh.</p>

    {{-- The Mudir signs by hand: the space is left empty (no signature or stamp image). --}}
    <table class="signs">
        <tr>
            {{-- Three lines above the space on both sides, so the names line up. --}}
            <td>
                &nbsp;<br>&nbsp;<br>Pemohon,
                <div class="space"></div>
                <span class="name">{{ $student->user->name }}</span><br>
                NIM {{ $student->nim }}
            </td>
            <td>
                {{ $city ? $city.', ' : '' }}{{ $request->updated_at->translatedFormat('j F Y') }}<br>
                Menyetujui,<br>{{ $signatory?->position ?? 'Mudir' }}
                <div class="space"></div>
                @if ($head)
                    <span class="name">{{ $head }}</span>
                @else
                    ____________________
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
