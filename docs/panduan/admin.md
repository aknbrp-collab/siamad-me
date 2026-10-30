# Panduan Admin (Bagian Akademik)

Admin mengelola data induk, data orang, semester, dan memverifikasi pembayaran.

## Persiapan awal (sekali)

Isi berurutan dari menu **Akademik**:
1. **Program studi** — kode (mis. `MA`) dan nama.
2. **Kelas** — satu kelas untuk setiap rombongan (angkatan) **Putra** atau **Putri**. Isi **nama** tanpa
   tingkat (mis. *Putra A*), **semester pertama**, dan **tingkat awal** (biasanya Semester I). SIAKAD
   menamai kelasnya *Semester I Putra A*, dan kelas itu **naik sendiri** setiap semester: saat semester
   berikutnya diaktifkan namanya menjadi *Semester II Putra A*, lalu *Semester III*, dan seterusnya (ganjil →
   genap dan ke tahun akademik berikutnya). Rombel dan nilai semester lalu tetap memakai nama kelas pada
   semester itu. Mahasantri hanya dapat ditempatkan di kelas yang sesuai jenis kelaminnya.

   Kelas yang dibuat sebelum aturan ini (bertanda **Sampai …**) tetap memakai nama dan tingkatnya, dan hanya
   dapat dipakai sampai semester yang aktif saat itu. Untuk semester berikutnya buat kelas baru, mis.
   *Putra A* mulai 2026/2027 Genap pada tingkat Semester II untuk mahasantri yang sekarang di Semester I.
3. **Mata kuliah** — kode, nama, kitab, dan SKS. Mata kuliah yang tidak dipakai lagi cukup
   dinonaktifkan, jangan dihapus.
4. **Skala nilai** — rentang nilai akhir (0–100) menjadi huruf dan bobot. Salah satu rentang harus mulai
   dari 0.
5. **Tahun akademik** → **Tambah tahun akademik** (format `2026/2027`). Semester ganjil dan genap dibuat
   otomatis dengan status *Rencana*.

## Data orang

Menu **Data orang**:
- **Mahasantri** — **Tambah mahasantri**. NIM menjadi ID login; kata sandi sementara tampil sekali
  (serahkan atau unduh).
- **Muhadhir** — **Tambah muhadhir**. NIDM menjadi ID login.
- **Wali santri** — **Tambah wali santri**, lalu hubungkan dengan satu atau beberapa mahasantri. Kode
  login (WS-…) dibuat otomatis. Wali hanya melihat mahasantri yang terhubung.
- **Impor** — buat banyak akun mahasantri atau muhadhir, atau **mata kuliah**, sekaligus: **Unduh
  templat** → isi di Excel → unggah → **Periksa berkas** (baris bermasalah ditampilkan) → **Impor**.
  Untuk akun, lembar kredensial berisi ID login dan kata sandi sementara diunduh otomatis; lembar ini
  tidak disimpan di mana pun: simpan dengan aman lalu bagikan. Mata kuliah diimpor dengan kode, nama,
  kode program studi, SKS, dan kitab; kode yang sudah ada ditolak, tidak ditimpa.
- **Data tahun sebelumnya** — saat Superadmin mengaktifkan **mode pemeliharaan** (pita kuning di atas
  halaman), semester yang ditutup dapat diubah, dan **Tambah tahun akademik** menawarkan pilihan
  **Tahun akademik sebelumnya (input data)** yang kedua semesternya langsung ditutup. Semua perubahan
  tercatat di log audit.

## Data tahun sebelumnya (dari arsip)

Menu **Data orang → Data tahun sebelumnya**, hanya selama mode pemeliharaan aktif. Pilih semester yang
sudah ditutup, lalu:
1. **Penempatan kelas**: unduh templat, isi `nim` dan `class` (nama kelas persis seperti di halaman
   Kelas), unggah, **Periksa berkas**, lalu **Impor**. Mahasantri yang sudah lulus atau keluar tetap
   bisa ditempatkan. Kelas harus sesuai jenis kelamin; mahasantri yang sudah punya kelas di semester itu
   dilaporkan, tidak dipindah.
2. **Nilai akhir**: isi `nim`, `subject_code`, `score` (0–100) dan/atau `letter` (huruf dari arsip,
   bobotnya mengikuti skala nilai), serta `teacher_nidm` bila kelas kuliah mata kuliah itu belum ada di
   SIAKAD (cukup sekali per mata kuliah dan kelas dalam satu berkas). Nilai disimpan sebagai nilai
   akhir sehingga KHS, transkrip, dan IPK langsung lengkap. Nilai yang sudah final tidak ditimpa.

Baris yang bermasalah ditampilkan beserta alasannya dan dilewati; perbaiki lalu impor ulang berkasnya.

**Koreksi satu nilai.** Selama mode pemeliharaan aktif, KHS semester yang ditutup di **Rekam akademik**
mahasantri menampilkan tombol pensil per mata kuliah: isi nilai angka atau huruf dan **alasannya**.
Nilai lama tetap tercatat di log audit.

Di halaman setiap orang ada kartu **Akun login**: **Atur ulang kata sandi** (membuat kata sandi
sementara baru) dan **Nonaktifkan login**. Jangan menghapus orang; nonaktifkan atau ubah statusnya
(cuti, lulus, keluar).

**Dokumen** — KTP, KK, dan ijazah yang diunggah mahasantri/muhadhir. Periksa lalu **Verifikasi**, atau
**Tolak** dengan catatan (pemilik melihat catatan dan dapat mengunggah ulang).

## Jadwal

Jadwal dibuat dari **kelas kuliah**: mata kuliah × kelas × muhadhir × **hari, jam mulai–selesai, ruang**
(halaman semester → tab **Kelas kuliah**). SIAKAD menolak jadwal yang **bentrok**: muhadhir, kelas, atau
ruang yang sama pada jam yang tumpang tindih di hari yang sama; pesannya menyebut kelas kuliah yang
bentrok. Saat menyalin kelas kuliah dari semester lain, yang bentrok tidak disalin dan dilaporkan.

**Kelas gabungan.** Bila bentroknya dengan kelas kuliah **mata kuliah dan muhadhir yang sama** di kelas lain
(mis. Fiqh putra dan putri diajar bersama), SIAKAD tidak langsung menolak, tetapi bertanya:
- **Kelas gabungan**: disimpan, dan kedua kelas digabung untuk dokumen mengajar (satu RPS dan satu jurnal).
  Di daftar kelas kuliah muncul tanda **Gabungan dengan …**, dan di jadwal keduanya tampil sebagai satu blok.
- **Salah input, saya perbaiki**: kembali ke formulir untuk membetulkan jam, kelas, atau muhadhir.

Bentrok dengan mata kuliah lain atau muhadhir lain tetap ditolak. Kelas gabungan yang disalin ke semester
berikutnya tetap tergabung.

Menu **Akademik → Jadwal** menampilkan jadwal mingguan per **kelas**, **muhadhir**, atau **ruang**, dan
dapat dicetak (**Cetak**).

## Ujian (UTS dan UAS)

Menu **Akademik → Ujian**.

1. **Ruang ujian** (tombol di kanan atas): nama, kapasitas, dan **Putra** atau **Putri**. Mahasantri
   hanya ditempatkan di ruang sesuai jenis kelaminnya.
2. **Tambah masa ujian**: UTS atau UAS dan rentang tanggalnya (satu UTS dan satu UAS per semester).
3. Buka masa ujian → tab **Jadwal ujian** → **Tambah ujian**: mata kuliah (kelas kuliah), tanggal di
   dalam masa ujian, jam mulai–selesai, dan pengawas (boleh kosong). Ujian yang **bentrok** ditolak:
   kelas atau pengawas yang sama pada jam yang tumpang tindih di tanggal yang sama. Ruang boleh dipakai
   bersama: beberapa mata kuliah dapat diujikan di satu ruang pada jam yang sama.
4. Di kartu **Ruang dan kursi**, centang ruang putra dan putri untuk masa ujian ini → **Simpan ruang**
   (jumlah mahasantri dan kursi per putra/putri ditampilkan), lalu **Acak nomor kursi**. Setiap
   mahasantri mendapat **satu ruang dan nomor kursi yang sama untuk semua ujian** di masa ini. Nomor
   kursi dimulai dari 1 di tiap ruang; jika kursi putra/putri kurang, SIAKAD menolak dan menyebut
   jumlahnya. Mengubah ruang atau menambah/menghapus ujian menghapus nomor kursi (acak lagi).
5. **Terbitkan**. Syaratnya nomor kursi sudah diacak. Mahasantri peserta mendapat notifikasi. Setelah
   terbit, jadwal dan kursi terkunci; **Tarik terbitan** untuk mengubahnya lagi.

Tab **Peserta & kartu** menampilkan status tiap mahasantri: ruang dan kursinya, pembayaran, jumlah mata
kuliah yang masuk kartu, dan dispensasi. Syarat kartu ujian: **tagihan semester lunas**, dan hanya mata kuliah dengan
**kehadiran minimal** (Pengaturan, bawaan 75%) yang masuk kartu. Mahasantri yang belum memenuhi syarat
dapat diberi **Dispensasi** berdasarkan surat rektor (nomor, tanggal, catatan): dispensasi
menggugurkan kedua syarat dan tercantum di kartu. Tombol **Kartu** mencetak kartu ujian (PDF A5).

## Pembimbing akademik

Menu **Data orang → Pembimbing akademik**. Pilih muhadhir di kiri, lalu centang mahasantri (saring per
kelas, nama, atau **Hanya yang belum punya pembimbing**) dan klik **Tetapkan kepada …**. Setiap mahasantri
memiliki satu pembimbing pada satu waktu; menetapkan pembimbing lain menutup yang lama, dan riwayatnya
tetap tersimpan. **Akhiri** menghentikan bimbingan (mis. mahasantri lulus). Pembimbing melihat KHS,
presensi, transkrip, dan catatan kegiatan mahasantri bimbingannya, dan berdiskusi di forum bimbingan.

## Membuka semester baru

1. **Tahun akademik** → buka semester baru.
2. Tab **Kelas**: **Salin dari semester lain** untuk menyalin rombel. Setiap kelas membawa mahasantrinya
   ke semester berikutnya dan naik satu tingkat (Semester I Putra A → Semester II Putra A). Kelas lama yang
   sudah tidak dipakai dipasangkan ke kelas satu tingkat di atasnya dengan jenis kelamin sama. Pasangan
   kelas dapat diubah sebelum menyalin. Mahasantri yang jenis kelaminnya tidak sesuai dengan kelas
   tujuan dilewati dan dapat ditempatkan manual. Saat mencari mahasantri untuk sebuah kelas, hanya yang
   sejenis kelamin yang tampil; tanda *Seharusnya: Semester …* muncul jika tingkatnya tidak sesuai
   (tetap boleh ditempatkan, mis. untuk mengulang). Mahasantri lulus, keluar,
   atau cuti dilewati. Tambahkan mahasantri satu per satu lewat
   **Tambah mahasantri: ketik NIM atau nama…** di kelas yang dipilih.
3. Tab **Kelas kuliah**: **Tambah kelas kuliah** untuk setiap mata kuliah × kelas × muhadhir × jadwal.
   Karena kelas naik tingkat, mata kuliahnya berbeda tiap semester: **Salin kelas kuliah** hanya menyalin
   kelas yang tingkatnya sama (kelas lama dalam masa pakainya); kelas yang naik tingkat diisi manual.
   Peserta kuliah terisi otomatis dari rombel kelas.
4. Minta Admin Keuangan **Buat tagihan** semester ini.
5. Tutup semester lama (setelah nilai final), lalu **Aktifkan** semester baru. Hanya satu semester yang
   boleh aktif.

**Menutup semester** (**Tutup semester**) membuat semua rombel, kelas kuliah, presensi, dan nilai
semester itu hanya dapat dibaca. Jika masih ada nilai yang belum final, SIAKAD meminta konfirmasi. Hanya
Superadmin yang dapat membukanya kembali.

## Templat

Menu **Templat** (Admin, Superadmin, Pemantau akademik) berisi semua templat yang diunduh dari SIAKAD:
templat impor (mahasantri, muhadhir, mata kuliah), templat data tahun sebelumnya, serta **RPS** dan
**jurnal mengajar**. Setiap templat punya versi **bawaan** yang sudah dirancang (judul kolom berwarna,
lebar kolom rapi, baris contoh, dan lembar **Petunjuk**; dokumen Word dengan kop lembaga dan tabel).
- **Unduh**, ubah di Excel/Word, lalu **Unggah pengganti**. Semua unduhan sejak itu memakai file Anda.
- Templat Excel: jangan ubah nama kolom di baris pertama (SIAKAD membacanya); file yang kehilangan
  kolom wajib ditolak.
- Templat Word boleh memakai isian seperti `${mata_kuliah}`, `${kelas}`, `${muhadhir}`, `${semester}`
  (daftar lengkap ada di halaman). Jurnal: letakkan `${no}`, `${tgl}`, `${materi}`, `${metode}`,
  `${hadir}`, `${catatan}` dalam satu baris tabel; baris itu diulang untuk setiap pertemuan. Isian lain
  yang tidak dikenal ditolak.
- **Pakai bawaan** mengembalikan templat bawaan (file lama tetap tersimpan sebagai riwayat).

## Dokumen mengajar (RPS dan jurnal)

Menu **Akademik → Dokumen mengajar**: setiap kelas kuliah (atau kelas yang digabung) per semester dengan
status **RPS** dan **jurnal** muhadhir. Saring yang belum lengkap atau menunggu pemeriksaan.
- **Periksa**: buka berkasnya, lalu **Terima** atau **Kembalikan** dengan catatan; muhadhir mendapat
  notifikasi dan mengunggah versi baru.
- **Gabung kelas**: untuk kelas dengan mata kuliah dan muhadhir yang sama yang diajar dalam satu sesi
  (mis. putra dan putri), gabungkan agar cukup satu RPS dan satu jurnal. Selama belum ada unggahan,
  gabungan dapat **dipisahkan** lagi.
- Ikon printer membuka jurnal mengajar (PDF) dari pertemuan-pertemuan kelas itu.

## Persuratan

**Persuratan → Surat keluar.**
- **Buat surat**: pilih jenis surat, tanggal, dan (bila jenisnya tentang seseorang) cari lalu pilih
  mahasantri atau muhadhir. Teks terisi otomatis dari templat dengan data SIAKAD; periksa dan ubah bila
  perlu (**Isi ulang dari templat** mengembalikannya). Isi perihal dan, bila perlu, **Kepada**. Penanda
  tangan dan stempel mengikuti jenis surat dan dapat diganti. **Terbitkan surat** memberi nomor urut
  berikutnya (satu nomor urut untuk semua jenis, mulai dari 1 setiap Januari).
- **Register**: semua surat per tahun, dapat dicari dan disaring per jenis, diunduh ke Excel; tombol
  **PDF** mencetak surat (dengan atau tanpa gambar tanda tangan dan stempel).
- **Templat Word per jenis surat** (halaman **Templat** → *Persuratan*): unduh contoh Word, atur kop,
  tata letak, tanda tangan (`${ttd}`) dan stempel (`${stempel}`), lalu unggah. Surat baru jenis itu diisi
  dari templat tersebut (`${nomor}`, `${perihal}`, `${kepada}`, `${isi}`, `${mahasantri.nama}` …) dan
  menjadi **PDF** bila LibreOffice terpasang di server; bila belum, tombol PDF mengunduh file **Word**.
  Tombol **Word** selalu memberi file Word-nya. Surat yang sudah terbit tetap memakai versi templat saat
  diterbitkan; surat yang dibatalkan dicetak dengan tata letak bawaan bertanda DIBATALKAN. Jenis surat
  tanpa templat Word tetap memakai tata letak PDF bawaan.
- Surat yang sudah terbit **tidak dapat diubah**. Bila salah, **batalkan** dengan alasan (nomornya tetap
  tercatat sebagai dibatalkan dan tidak dipakai lagi), lalu buat surat baru.
- **Permohonan**: permohonan surat dari mahasantri (mis. surat keterangan aktif kuliah) beserta
  keperluannya. **Buat suratnya** membuka formulir yang sudah terisi; setelah terbit mahasantri mendapat
  notifikasi dan dapat mengunduhnya. **Tolak** dengan alasan yang dilihat mahasantri.

**Persuratan → Surat masuk.** **Catat surat**: tanggal diterima, pengirim, nomor dan tanggal surat
pengirim, perihal, dan pindaian (PDF/foto). Nomor agenda dibuat otomatis per tahun (AGD/2026/…). Isi
**disposisi** (kepada siapa dan instruksinya) dan centang **Selesai** setelah ditindaklanjuti. Register
dapat dicari, disaring yang belum selesai, dan diunduh ke Excel.

## Verifikasi pembayaran

Menu **Verifikasi → Verifikasi pembayaran**. Anda tidak dapat memverifikasi pembayaran yang Anda catat
sendiri.
- **Setoran tunai**: hitung uang yang diserahkan Admin Keuangan. Centang pembayaran hanya jika uangnya
  **tidak ada** (beri alasan), lalu **Hitung & verifikasi**. Yang tidak dicentang menjadi terverifikasi
  dan mendapat kuitansi resmi.
- **Transfer**: cocokkan dengan rekening koran, lalu **Verifikasi** atau **Tolak** dengan alasan.
- Pilih rekening tujuan (**Transfer yang diverifikasi masuk ke**, **Uang tunai masuk ke**) agar
  pembayaran masuk ke buku kas rekening yang benar; rekening bawaan sudah terpilih.

Menu **Pembayaran belum diverifikasi** menunjukkan setiap pembayaran yang belum diverifikasi dan di mana
tertahan:
- **Tunai belum disetor**: uang masih dipegang orang yang mencatatnya (Admin Keuangan atau petugas).
- **Setoran belum diverifikasi**: sudah disetor, menunggu dihitung Admin (atau Admin Keuangan untuk
  setoran petugas).
- **Transfer belum diperiksa**: menunggu dicocokkan dengan rekening koran.

Bagian **Siapa yang memegang** merangkum per orang atau kelompok: jumlah, total, dan berapa hari yang
terlama sudah menunggu. **Ingatkan** mengirim notifikasi kepada mereka (paling banyak sekali sehari).

## Nilai dan pemantauan

**Pemantauan akademik** menampilkan presensi dan status nilai setiap kelas kuliah. Jika muhadhir perlu
mengoreksi nilai yang sudah final, klik **Buka kunci nilai** pada kelas kuliah itu di halaman
**Pemantauan akademik** dan isi alasannya (hanya selama semester masih terbuka). **Catatan kegiatan** untuk kegiatan pesantren dijelaskan di
[pemantau.md](pemantau.md).

**Rekam akademik** mahasantri (KHS per semester dan transkrip, beserta PDF) dibuka dari halaman
mahasantri.
