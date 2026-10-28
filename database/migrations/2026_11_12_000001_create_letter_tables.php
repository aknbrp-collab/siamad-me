<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v3 Step 6: letters (D10, D12). Letter types with their template, outgoing
 * letters (one number counter shared by all types, per Gregorian year),
 * incoming letters with an agenda number, and mahasantri requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 20)->unique();
            // Whom the letter is about: none | student | teacher.
            $table->string('subject', 10)->default('none');
            // Mahasantri may request it themselves (Surat saya).
            $table->boolean('requestable')->default(false);
            $table->text('template');
            $table->foreignId('signatory_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->foreignId('stamp_id')->nullable()->index()->constrained()->nullOnDelete();
            // Print the signature and stamp images by default.
            $table->boolean('print_marks')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('letter_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('letter_type_id')->index()->constrained()->restrictOnDelete();
            $table->string('purpose', 500);
            $table->string('status', 10)->default('pending')->index(); // pending | approved | rejected
            $table->foreignId('decided_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('letters', function (Blueprint $table) {
            $table->id();
            $table->string('number', 100)->unique();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->foreignId('letter_type_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('letter_request_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('recipient', 255)->nullable(); // Kepada Yth.
            $table->string('subject_line', 255); // Perihal
            $table->text('body');
            $table->date('issued_on');
            $table->foreignId('issued_by')->index()->constrained('users')->restrictOnDelete();
            $table->foreignId('signatory_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('stamp_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->boolean('print_marks')->default(true);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->index()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['year', 'sequence']);
        });

        Schema::create('incoming_letters', function (Blueprint $table) {
            $table->id();
            $table->string('agenda_number', 30)->unique();
            $table->date('received_on')->index();
            $table->string('sender', 255);
            $table->string('sender_number', 100)->nullable();
            $table->date('sender_date')->nullable();
            $table->string('subject', 500);
            $table->string('scan_path')->nullable();
            $table->string('disposition_to', 255)->nullable();
            $table->text('disposition_note')->nullable();
            $table->timestamp('disposition_done_at')->nullable();
            $table->foreignId('recorded_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        $now = now();
        $body = fn (string $text) => trim(preg_replace('/^ {12}/m', '', $text));
        foreach ([
            ['Surat Keterangan Aktif Kuliah', 'SKA', 'student', true, $body('
            Yang bertanda tangan di bawah ini, Mudir {lembaga.nama}, menerangkan bahwa:

            Nama: {mahasantri.nama}
            NIM: {mahasantri.nim}
            Program studi: {mahasantri.prodi}
            Kelas: {mahasantri.semester}

            adalah benar mahasantri {lembaga.nama} yang aktif mengikuti perkuliahan pada {tahun_akademik}.

            Surat keterangan ini dibuat untuk keperluan {keperluan}.

            Demikian surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.')],
            ['Surat Tugas', 'ST', 'teacher', false, $body('
            Mudir {lembaga.nama} menugaskan:

            Nama: {muhadhir.nama}
            NIDM: {muhadhir.nidm}

            untuk ...

            Demikian surat tugas ini dibuat untuk dilaksanakan dengan penuh tanggung jawab.')],
            ['Surat Undangan', 'UND', 'none', false, $body('
            Dengan hormat,

            Kami mengundang Bapak/Ibu untuk hadir pada:

            Hari, tanggal: ...
            Waktu: ...
            Tempat: ...
            Acara: ...

            Atas perhatian dan kehadirannya kami ucapkan terima kasih.')],
            ['Surat Rekomendasi', 'REK', 'student', true, $body('
            Yang bertanda tangan di bawah ini, Mudir {lembaga.nama}, memberikan rekomendasi kepada:

            Nama: {mahasantri.nama}
            NIM: {mahasantri.nim}
            Program studi: {mahasantri.prodi}

            untuk keperluan {keperluan}. Selama menjadi mahasantri, yang bersangkutan berkelakuan baik.

            Demikian surat rekomendasi ini dibuat untuk dipergunakan sebagaimana mestinya.')],
            ['Surat Keterangan Lulus', 'SKL', 'student', false, $body('
            Yang bertanda tangan di bawah ini, Mudir {lembaga.nama}, menerangkan bahwa:

            Nama: {mahasantri.nama}
            NIM: {mahasantri.nim}
            Program studi: {mahasantri.prodi}

            telah menyelesaikan seluruh kewajiban akademik dan dinyatakan LULUS.

            Demikian surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.')],
        ] as [$name, $code, $subject, $requestable, $template]) {
            DB::table('letter_types')->insert([
                'name' => $name, 'code' => $code, 'subject' => $subject, 'requestable' => $requestable,
                'template' => $template, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_letters');
        Schema::dropIfExists('letters');
        Schema::dropIfExists('letter_requests');
        Schema::dropIfExists('letter_types');
    }
};
