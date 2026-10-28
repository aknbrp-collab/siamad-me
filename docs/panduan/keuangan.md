# Panduan Admin Keuangan

Admin Keuangan mengatur biaya, membuat tagihan, mencatat pembayaran, menyetor uang tunai, dan
mengelola beasiswa. **Pembayaran baru terhitung setelah diverifikasi Admin**, bukan saat Anda
mencatatnya. Pemisahan tugas ini disengaja.

## 1. Biaya dan tarif (awal tahun akademik)

Menu **Keuangan → Biaya**:
1. **Tambah jenis biaya**: nama (SPP, Daftar Ulang, Asrama…) dan frekuensi: per semester, bulanan
   (ditagih 6× per semester), atau sekali per mahasantri.
2. **Tambah tarif** untuk tahun akademik: nominal, dan bila perlu khusus kelas dan/atau angkatan. Saat
   tagihan dibuat, tarif yang paling spesifik yang dipakai (kelas + angkatan → kelas → angkatan → semua).
3. Tahun berikutnya: **Salin tarif** dari tahun lalu, lalu ubah yang naik.

Mengubah tarif tidak mengubah tagihan yang sudah dibuat.

## 2. Beasiswa

Menu **Beasiswa**: **Tambah beasiswa** (sumber dana, jenis: persentase, nominal tetap, atau bebas biaya
penuh, dan kuota). Pilih beasiswa → **Berikan kepada mahasantri** per semester. Jika tagihan semester
itu sudah ada, potongan langsung ditambahkan ke tagihan.

## 3. Membuat tagihan (awal semester)

Menu **Tagihan** → pilih semester → **Buat tagihan**. Pratinjau menampilkan siapa yang ditagih dan
berapa. Hanya mahasantri aktif yang sudah ditempatkan di kelas yang ditagih; yang sudah punya tagihan
dilewati, jadi aman dijalankan ulang (mis. setelah mahasantri baru ditempatkan).

Status tagihan: **Belum dibayar**, **Dibayar sebagian**, **Lunas**, **Batal**. **Ekspor** mengunduh
daftar tagihan ke Excel.

## 4. Mencatat pembayaran

Buka tagihan → **Catat pembayaran**: jumlah, metode (**Tunai** / **Transfer bank**), tanggal, referensi
bank (wajib untuk transfer), dan bukti (opsional). Aturan cicilan:
- maksimal **3 kali** per tagihan (diatur Superadmin);
- setiap cicilan minimal **Rp 200.000**, kecuali pembayaran yang melunasi sisa tagihan;
- tidak boleh melebihi sisa tagihan; cicilan terakhir harus melunasi.

Setelah dicatat, Anda dapat mencetak slip bertanda *BELUM TERVERIFIKASI* untuk pembayar. Kuitansi resmi
baru tersedia setelah Admin memverifikasi. Saat mencetak kwitansi, pilih dicetak dengan gambar tanda
tangan dan stempel (mis. Bendahara dan stempel keuangan) atau tanpa, untuk ditandatangani basah.

## 5. Menyetor uang tunai

Menu **Setoran tunai**: centang pembayaran tunai yang Anda terima lalu klik **Setor …**. Serahkan uang
beserta nomor setoran (STR/…) kepada Admin. Admin menghitung dan memverifikasi; jika ada uang yang
kurang, pembayaran itu ditolak dengan alasan. Transfer tidak perlu disetor: Admin memverifikasinya
langsung dari rekening koran.

## 5a. Konfirmasi transfer dan petugas pembayaran

Mahasantri dan wali santri dapat mengonfirmasi transfer sendiri beserta bukti transfernya. Menu
**Konfirmasi transfer** menampilkan yang menunggu: buka **Bukti transfer**, cocokkan dengan rekening koran, lalu
**Verifikasi** (kuitansi resmi terbit) atau **Tolak** dengan alasan (pengirim melihat alasannya). Admin
juga dapat memverifikasinya. Transfer yang Anda catat sendiri tetap diverifikasi oleh Admin.

Halaman yang sama memuat pembayaran yang dicatat **petugas pembayaran** (ditandai *Petugas pembayaran*)
dan **setoran tunai** mereka: **Hitung & verifikasi** seperti Admin menghitung setoran Anda.
Rekening tujuan transfer diatur Superadmin di **Pengaturan**.

## 6. Tunggakan dan membatalkan tagihan

**Tunggakan dari tahun akademik yang sudah ditutup** tetap dapat dibayar. Di **Tagihan**, pilih
**Semua semester** dan status **Belum lunas** untuk melihat semua tagihan yang belum lunas dari semester
mana pun, lalu buka tagihannya dan **Catat pembayaran** seperti biasa.

Tagihan yang belum memiliki pembayaran dapat dibatalkan (**Batalkan tagihan**, wajib alasan), lalu dibuat
ulang dengan **Buat tagihan**. Tagihan semester yang sudah ditutup tidak dapat dibatalkan, tetapi
pembayaran tunggakannya tetap dapat dicatat.

## 7. Ringkasan

Menu **Ringkasan** (Superadmin) dan halaman tagihan menampilkan yang ditagihkan, terkumpul
(terverifikasi), menunggu verifikasi, dan tunggakan terbesar.

### Pembayaran yang belum diverifikasi

Menu **Pembayaran belum diverifikasi** menunjukkan setiap pembayaran yang belum diverifikasi dan di mana
tertahan:
- **Tunai belum disetor**: uang masih dipegang orang yang mencatatnya (Admin Keuangan atau petugas).
- **Setoran belum diverifikasi**: sudah disetor, menunggu dihitung Admin (atau Admin Keuangan untuk
  setoran petugas).
- **Transfer belum diperiksa**: menunggu dicocokkan dengan rekening koran.

Bagian **Siapa yang memegang** merangkum per orang atau kelompok: jumlah, total, dan berapa hari yang
terlama sudah menunggu. **Ingatkan** mengirim notifikasi kepada mereka (paling banyak sekali sehari).

## 8. Buku kas

Menu **Keuangan → Buku kas**.

**Persiapan (sekali).** Di **Rekening & kategori**, tambahkan **kas tunai** dan setiap **rekening bank**
beserta **saldo awal** dan **tanggal saldo awal** (jumlah uang pada awal hari itu; buku kas hanya
menghitung transaksi sejak tanggal itu). Pilih **rekening bawaan** untuk pembayaran tunai dan transfer.
Kategori pemasukan lain dan pengeluaran sudah tersedia (Honorarium, ATK, Listrik, Konsumsi, …) dan
dapat ditambah atau dinonaktifkan.

**Pembayaran tagihan** otomatis menjadi pemasukan setelah **diverifikasi**, masuk ke rekening yang
dipilih: saat mencatat pembayaran (**Ke rekening**), atau saat memverifikasi transfer / menerima setoran
(pilihan **Transfer yang diverifikasi masuk ke** / **Uang tunai masuk ke**). Pembayaran yang sudah
terverifikasi sebelum buku kas dipakai dibukukan sekali melalui kartu **Pembayaran tagihan yang belum
dibukukan** di halaman Rekening & kategori.

**Buku besar**: pilih rekening dan bulan untuk melihat saldo awal, uang masuk, keluar, dan saldo
berjalan.
- **Pemasukan lain** (infaq, hibah, …) dan **Pengeluaran**: rekening, tanggal, jumlah, kategori,
  diterima dari / dibayar kepada, keterangan, dan **bukti** (foto/PDF kuitansi). Nomor bukti dibuat
  otomatis: **BKM** (kas masuk) dan **BKK** (kas keluar).
- **Pindah dana**: memindahkan uang antarrekening, mis. menyetor kas ke bank (nomor **MUT**); bukan
  pemasukan atau pengeluaran.
- Catatan yang salah **dibatalkan** (ikon larangan) dengan alasan; catatan tetap ada di log audit.
  Kedua sisi pindah dana ikut dibatalkan.
- **Excel** mengunduh buku besar bulan itu.
- **Tutup buku**: setelah satu bulan diperiksa, tutup buku sampai bulan itu. Catatan pada bulan yang
  sudah ditutup hanya bisa ditambah atau dibatalkan selama **mode pemeliharaan** aktif.

**Laporan**: pilih rentang bulan untuk melihat pemasukan (pembayaran tagihan dan per kategori),
pengeluaran per kategori, dan surplus/defisit. **Cetak laporan** membuat PDF berkop dengan saldo setiap
rekening dan tanda tangan (pilih penanda tangan dan stempel saat mencetak). Saldo setiap rekening juga
tampil di **Ringkasan**.

## 9. Data keuangan tahun sebelumnya

Menu **Keuangan → Data tahun sebelumnya**, hanya selama Superadmin mengaktifkan **mode pemeliharaan**.
- **Tagihan & pembayaran** (per semester yang sudah ditutup): satu baris per mahasantri — `nim`,
  `billed` (total tagihan), `paid` (total dibayar), `paid_on`, `method` (tunai/transfer), `account`
  (nama rekening), `description`. Tagihan dibuat dengan satu baris; pembayaran disimpan terverifikasi
  (sumber: arsip) dan dibukukan ke rekening itu. Mahasantri yang sudah punya tagihan semester itu
  dilewati.
- **Pemasukan & pengeluaran**: `date`, `type` (masuk/keluar), `category`, `amount`, `account`, `party`,
  `description`. Tercatat seperti catatan buku kas biasa dengan nomor BKM/BKK tahunnya.

Atur dulu rekening dan **saldo awal** pada tanggal awal pembukuan (mis. 1 Juli 2025); transaksi sebelum
tanggal itu ditolak karena sudah termasuk dalam saldo awal.

