# Booking Module — Developer Documentation

## Struktur File

```
app/
├── Http/Controllers/Api/
│   └── BookingController.php   ← Tipis: hanya terima & kirim HTTP
└── Services/
    └── BookingService.php      ← Gemuk: semua logika bisnis di sini
```

---

## Changelog

### v1.1.0 — Refactor & Bug Fix
> File: `BookingController.php` + `BookingService.php`

| # | Kategori   | Bug / Masalah Lama                                                              | Solusi Baru                                                                                                          |
|---|------------|---------------------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------|
| 1 | 🔴 Bug     | `customer_code` bisa berubah saat customer yang sama di-update                 | Pakai `firstOrCreate` hanya untuk `customer_code`, update profil via `fill()->save()` secara terpisah               |
| 2 | 🔴 Bug     | Race condition: `lockForUpdate` hanya di `BookingFinance`, bukan di `payments` | Tambahkan `lockForUpdate()` pada query `BookingPayment::sum('amount')` agar concurrent request di-block             |
| 3 | 🟡 Improve | Total addon di-query ulang ke DB di `calculateFinalPrice()`                    | Hitung `addonTotal` langsung dari data di memori di `attachAddons()`, hasil dikembalikan sebagai return value        |
| 4 | 🟡 Improve | `firstOrCreate + if(empty())` tidak konsisten untuk update customer            | Ganti dengan `firstOrCreate` untuk generate kode + `fill()->save()` untuk selalu update profil                      |
| 5 | 🟡 Improve | `Log::error($e)` tanpa konteks                                                 | Log diperluas: `user_id`, `job_package_id`, `error_message`, `file`, `line`, `trace` (disembunyikan di production) |
| 6 | 🟢 Arch    | Semua logika di dalam `BookingController@store` (300+ baris)                   | Dipecah ke `BookingService` dengan 7 method terpisah, masing-masing satu tanggung jawab                             |

---

## Alur Pembuatan Booking

```
POST /api/bookings
        │
        ▼
[1] Validasi Input (Validator)
        │
        ▼
[2] resolveCustomer()
    • firstOrCreate (user_id + phone)
    • customer_code hanya di-set saat CREATE
    • fill()->save() untuk selalu update profil
        │
        ▼
[3] findPackageForUser()
    • Validasi package milik user (whereHas jobType)
        │
        ▼
[4] createBooking()
    • Simpan snapshot job_type & job_package
        │
        ▼
[5] attachAddons()
    • Simpan tiap add-on
    • Return: addonTotal (float) dari memori, bukan query DB
        │
        ▼
[6] createFinance()
    • final_price = package_price + addonTotal - discount
    • min(0) agar tidak negatif
        │
        ▼
[7] processInitialPayment() [opsional]
    • lockForUpdate() pada Finance DAN Payment
    • Throw Exception jika melebihi tagihan
        │
        ▼
[8] recordHistory()
    • Audit trail: siapa, kapan, total berapa
        │
        ▼
DB::commit() → Response 201
```

---

## Panduan Maintenance

### Menambah Payment Method Baru
1. Buka `BookingController.php` → cari rule validasi `payment.payment_method`
2. Tambahkan nilai baru di array `in:Cash,Transfer,QRIS,...`
3. Tidak perlu ubah `BookingService` karena nilai disimpan apa adanya.

### Mengubah Status Pembayaran (DP/Partial/Pelunasan)
1. Buka `BookingService.php` → method `resolvePaymentStatus()`
2. Ubah kondisi sesuai kebutuhan bisnis.

### Menambah Field ke History
1. Buka `BookingService.php` → method `recordHistory()`
2. Ubah format `$description` atau tambahkan kolom baru ke migration.

### Menambah Validasi Booking Baru
1. Buka `BookingController.php` → array `Validator::make()`
2. Tambahkan rule baru.
3. Jika perlu logika bisnis, tambahkan ke method terkait di `BookingService`.

---

## Catatan Penting

### Lock untuk Concurrent Payment
Method `processInitialPayment()` menggunakan `lockForUpdate()` pada dua tabel:
```php
BookingFinance::lockForUpdate()->first();     // lock baris finance
BookingPayment::lockForUpdate()->sum(...);    // lock semua baris payment booking ini
```
**Penting**: Method ini HARUS dipanggil di dalam `DB::beginTransaction()`.
Jika dipanggil di luar transaksi, lock tidak akan berfungsi.

### customer_code
`customer_code` hanya di-generate sekali saat customer pertama dibuat.
Ini dijamin oleh pola `firstOrCreate` di `resolveCustomer()`.
Jangan pindahkan `customer_code` ke kolom `$values` (parameter kedua) `firstOrCreate`.

### Log di Production
Stack trace disembunyikan di environment production:
```php
'trace' => app()->isProduction() ? '[hidden]' : $e->getTraceAsString(),
```
Untuk debugging di production, gunakan tool seperti Sentry atau Telescope.
