# Self Circulation (Kiosk) — SLiMS Plugin by Erwan Setyo Budi

Plugin **Self Circulation (Kiosk)** untuk **peminjaman & pengembalian mandiri** melalui halaman OPAC (mode kiosk/fullscreen).
- Terimakasih kepada Fathul Ilmi yang telah mensponsori penyempurnaan plugin ini.
---

## Fitur Utama
- **Transaksi Mandiri**: PINJAM & KEMBALI via OPAC path
- **Validasi Anggota**:
  - Member ID harus valid
  - Cek status pending
  - Cek masa berlaku keanggotaan (expire_date)
  - Validasi PIN:
    - `mpasswd` (hash) dengan `password_verify()`
    - fallback `pin` (legacy)
- **Validasi Item**:
  - Barcode/item_code harus valid
  - Cek status item dari `mst_item_status.no_loan = 1` (tidak boleh dipinjam)
  - Cegah pinjam item yang sedang dipinjam (loan aktif)
- **Loan Rules Dinamis** (tabel `mst_loan_rules`):
  - Prioritas pencarian rules:
    1) `member_type_id + coll_type_id + gmd_id`
    2) `member_type_id + coll_type_id`
    3) `member_type_id` saja
- **Kiosk UX**:
  - Auto-focus ke barcode (tidak mengganggu saat mengetik member/pin)
  - Auto-clear PIN setelah sukses
  - Auto-reset layar (timeout)
  - Tombol **Mode Kiosk** (fullscreen)
  - Tombol “lihat PIN” (ikon mata)
  - Disable right-click (jika diaktifkan pada versi Anda)
- **Struk/Receipt**:
  - Muncul setelah transaksi
  - Bisa print **A4** atau **Thermal 80mm**
- **Branding**:
  - Logo + nama perpustakaan + subname (mengikuti setting SLiMS)
- **Terintegrasi dengan Plugin Notifikasi WA dan Notifikasi Email**:
  - Plugin ini dapat terintegrasi dengan plugin notifikasi karena memanfaatkan hook circulation

---

## Persyaratan
- SLiMS Bulian (v9.x)
- PHP 7.4+ (disarankan)
- Akses printer (untuk fitur cetak), dan izin popup browser untuk window print

---

## Instalasi
1. **Copy folder plugin**
   - Letakkan ke:
     ```
     /plugins/self_circulation/
     ```
2. **Pastikan file utama plugin ada**
   - Contoh struktur minimal:
     ```
     plugins/
       self_circulation/
         self_circulation.plugin.php
         pages/
           kiosk.inc.php
         templates/
           kiosk_layout.inc.php
         assets/ (opsional)
           images/logo.png
     ```
3. **Aktifkan plugin di SLiMS**
   - Masuk Admin → **System → Plugins** → aktifkan **Self Circulation**
4. **Akses halaman kiosk**
   - Buka:
     ```
     index.php?p=self_circulation
     ```

---

## Setting (Opsional)
Plugin bisa dinyalakan/dimatikan lewat tabel `setting`.

### Enable/Disable Self Circulation
- **Aktifkan**
  ```sql
  INSERT INTO setting (setting_name, setting_value)
  VALUES ('enable_self_circulation','b:1;');
