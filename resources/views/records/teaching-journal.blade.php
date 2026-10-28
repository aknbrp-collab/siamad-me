<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Jurnal Mengajar {{ $offering->subject_code }}</title>
    @include('records.style')
    <style>
        table.grades td { vertical-align: top; }
        .sign-box { margin-top: 18px; width: 34%; float: right; text-align: center; }
    </style>
</head>
<body>
    @include('records.letterhead')

    <h2>JURNAL MENGAJAR</h2>

    <table class="identity">
        <tr><td>Mata kuliah</td><td>: {{ $offering->subject_code }} · {{ $offering->subject_name }} ({{ $offering->credits }} SKS)</td></tr>
        <tr><td>Kelas</td><td>: {{ $members->map(fn ($member) => $member->class_name)->join(', ') }}</td></tr>
        <tr><td>Muhadhir</td><td>: {{ $teacherName }}</td></tr>
        <tr><td>Semester</td><td>: {{ $offering->semester->label() }}</td></tr>
    </table>

    <table class="grades">
        <thead>
            <tr>
                <th class="center" style="width: 4%">No</th>
                <th style="width: 12%">Tanggal</th>
                <th style="width: 18%">Pokok bahasan</th>
                <th>Materi yang disampaikan</th>
                <th style="width: 13%">Metode</th>
                <th class="center" style="width: 8%">Hadir</th>
                <th style="width: 17%">Catatan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $index => $row)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $row['date']->translatedFormat('j M Y') }}<br><span class="muted">{{ $row['mode'] }}</span></td>
                    <td>{{ $row['topic'] }}</td>
                    <td>{!! nl2br(e($row['material'] ?? '')) !!}</td>
                    <td>{{ $row['method'] }}</td>
                    <td class="center">{{ $row['total'] > 0 ? $row['present'].' / '.$row['total'] : '—' }}</td>
                    <td>{!! nl2br(e($row['note'] ?? '')) !!}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="center muted">Belum ada pertemuan.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="sign-box">
        {{ \App\Models\Setting::get('institution.city') ? \App\Models\Setting::get('institution.city').', ' : '' }}{{ now()->translatedFormat('j F Y') }}<br>
        Muhadhir<br><br><br><br>
        <strong>{{ $teacherName }}</strong>
    </div>
</body>
</html>
