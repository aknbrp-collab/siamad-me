<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Contoh kop dan tanda tangan</title>
    @include('records.style')
</head>
<body>
    @include('records.letterhead')

    <h2>CONTOH DOKUMEN</h2>
    <p class="center muted">{{ $document->label() }} — contoh kop surat dan blok tanda tangan sebagaimana akan dicetak.</p>

    <p style="margin-top: 24px">Isi dokumen tampil di sini.</p>

    @include('records.signature')
</body>
</html>
