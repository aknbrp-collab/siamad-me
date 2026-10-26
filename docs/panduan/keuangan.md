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
baru tersedia setelah Admin memverifikasi.

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
