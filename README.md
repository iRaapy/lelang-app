# Lelang App — Backend (Laravel)

Backend REST API untuk platform lelang daring realtime, dibangun dengan Laravel 12, Sanctum (autentikasi), dan Reverb (WebSocket broadcasting).

## Anggota Kelompok

| NIM | Nama |
|-----|------|
| 2401010013 | I Rai Agus Aditya Prayuda |
| 2401010011 | Ni Putu Reina Puspita |
| 2401010008 | Dewa Ayu Manisha Candra |

## Tech Stack

- PHP 8.4 + Laravel 12
- MySQL 8
- Laravel Sanctum (autentikasi token-based)
- Laravel Reverb (WebSocket broadcasting)
- Queue: database driver
- Scheduler: `schedule:work`
- Pest (automated testing)
- Scribe (dokumentasi API otomatis)

## Fitur Wajib

- Registrasi, login, logout (Sanctum token-based)
- Setiap user dapat berperan sebagai penjual dan/atau penawar
- CRUD lelang — create, update, delete (hanya saat status `scheduled`)
- Status lelang terkelola otomatis: `scheduled` → `active` → `ended` via scheduler
- Validasi bid server-side:
  - Tawaran harus ≥ harga tertinggi + kelipatan minimum
  - Penawar tidak boleh bid lelang miliknya sendiri
  - Hanya diterima saat lelang berstatus aktif (antara `starts_at` dan `ends_at`)
  - Ditolak setelah lelang berakhir
  - Penawar tertinggi sebelumnya otomatis berstatus `outbid`
  - Race condition handling via DB transaction + `lockForUpdate()`
- Realtime broadcasting via Reverb:
  - `BidPlaced` — update harga & bid list ke semua viewer seketika
  - `BidderOutbid` — notifikasi privat ke penawar yang tergeser
  - `AuctionEnded` — pengumuman pemenang realtime saat lelang berakhir
- Otorisasi kanal privat via `routes/channels.php`
- Upload foto barang (single/multi, disimpan di local storage)

## Fitur Bonus

- Anti-sniping: memperpanjang waktu berakhir otomatis jika ada bid di 30 detik terakhir
- Buy Now: bid sebesar `buy_now_price` langsung mengakhiri lelang
- Presence channel: jumlah penonton aktif realtime
- Automated testing (Pest): 8 test case untuk logika penawaran
- Dokumentasi API otomatis (Scribe) di `/docs`

## Prasyarat

- PHP >= 8.2 dengan ekstensi: `mbstring, pdo_mysql, openssl, tokenizer, xml, ctype, json, bcmath, fileinfo, curl`
- Composer
- MySQL 8
- Node.js >= 18 (opsional, untuk build asset frontend jika monorepo)

## Instalasi

### 1. Clone repository

```bash
git clone https://github.com/iRaapy/lelang-app.git
cd lelang-app
```

### 2. Install dependencies

```bash
composer install
```

### 3. Konfigurasi environment

```bash
copy .env.example .env
php artisan key:generate
```

Edit `.env`, sesuaikan bagian berikut:

```env
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Makassar

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=lelang_db
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=reverb

SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
FRONTEND_URL=http://localhost:5173

REVERB_APP_ID=lelang-app
REVERB_APP_KEY=lelangkey
REVERB_APP_SECRET=lelangsecret
REVERB_HOST="localhost"
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

> **Catatan timezone**: `APP_TIMEZONE=Asia/Makassar` (WITA/UTC+8) penting agar scheduler dan validasi waktu lelang konsisten dengan waktu lokal.

### 4. Buat database

```sql
CREATE DATABASE lelang_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Migration + Seeder

```bash
php artisan migrate:fresh --seed
```

### 6. Storage link (untuk akses foto yang diupload)

```bash
php artisan storage:link
```

## Menjalankan Aplikasi (Development)

Butuh **4 terminal terpisah** berjalan bersamaan:

```bash
# Terminal 1 — HTTP server
php artisan serve

# Terminal 2 — WebSocket server (Reverb)
php artisan reverb:start

# Terminal 3 — Queue worker (proses broadcast event)
php artisan queue:work

# Terminal 4 — Scheduler (auto-transisi status lelang tiap menit)
php artisan schedule:work
```

API tersedia di: `http://localhost:8000/api`

## Akun Demo (Hasil Seeder)

| Role | Email | Password |
|------|-------|----------|
| Penjual | penjual@demo.com | password |
| Penawar 1 | penawar1@demo.com | password |
| Penawar 2 | penawar2@demo.com | password |

## Endpoint API

### Autentikasi (tidak perlu token)
| Method | Endpoint | Keterangan |
|--------|----------|------------|
| POST | `/api/register` | Registrasi user baru |
| POST | `/api/login` | Login, mengembalikan token |

### Membutuhkan `Authorization: Bearer <token>`

| Method | Endpoint | Keterangan |
|--------|----------|------------|
| POST | `/api/logout` | Logout (hapus token aktif) |
| GET | `/api/me` | Data user yang sedang login |
| GET | `/api/auctions` | Daftar lelang (`?status=active\|scheduled\|ended`) |
| POST | `/api/auctions` | Buat lelang baru (multipart/form-data untuk foto) |
| GET | `/api/auctions/my` | Daftar lelang milik user login |
| GET | `/api/auctions/{id}` | Detail lelang beserta daftar bid |
| PUT | `/api/auctions/{id}` | Update lelang (hanya saat `scheduled`) |
| DELETE | `/api/auctions/{id}` | Hapus lelang (hanya saat `scheduled`) |
| POST | `/api/auctions/{id}/bids` | Tempatkan tawaran baru |

## Kanal Broadcasting (WebSocket)

| Kanal | Tipe | Event | Keterangan |
|-------|------|-------|------------|
| `auction.{auctionId}` | Private | `BidPlaced`, `AuctionEnded` | Update realtime semua viewer |
| `App.Models.User.{id}` | Private | `BidderOutbid` | Notifikasi personal outbid |
| `presence-auction.{auctionId}` | Presence | — | Jumlah penonton aktif (bonus) |

Otorisasi kanal didefinisikan di `routes/channels.php`.

## Aturan Validasi Bid (Server-side)

1. `amount` ≥ `current_price` + `bid_increment`
2. Penawar ≠ penjual lelang
3. Status lelang harus `active` (antara `starts_at` dan `ends_at`)
4. Race condition ditangani dengan `DB::transaction()` + `lockForUpdate()`
5. Bid sebelumnya otomatis menjadi `outbid` saat ada tawaran lebih tinggi

## Struktur Folder Penting
app/

├── Events/              # BidPlaced, BidderOutbid, AuctionEnded

├── Http/

│   ├── Controllers/Api/ # AuthController, AuctionController, BidController

│   └── Requests/        # Form Request validasi (Auth, Auction, Bid)

├── Jobs/                # UpdateAuctionStatuses (scheduler tiap menit)

├── Models/              # User, Auction, AuctionImage, Bid

└── Policies/            # AuctionPolicy (otorisasi update/delete)
routes/

├── api.php              # Semua endpoint API

└── channels.php         # Otorisasi broadcasting channel
tests/

└── Feature/

└── BidLogicTest.php # 8 Pest test untuk logika penawaran
## Testing

Jalankan automated test untuk logika penawaran:

```bash
php artisan test
```

Test mencakup 8 kasus:
- Bid berhasil jika memenuhi minimum
- Bid ditolak jika kurang dari minimum
- Penjual tidak bisa bid lelang sendiri
- Bid ditolak jika lelang belum aktif (`scheduled`)
- Bid ditolak jika lelang sudah berakhir (`ended`)
- Penawar tertinggi sebelumnya otomatis menjadi `outbid`
- Buy Now langsung mengakhiri lelang dan menetapkan pemenang
- Anti-sniping memperpanjang waktu jika bid di detik-detik akhir

## Dokumentasi API

Dokumentasi API interaktif (Scribe) tersedia setelah server berjalan:
 http://localhost:8000/docs
 Mencakup semua endpoint dengan contoh request/response dan fitur "Try It Out".

Generate ulang setelah perubahan:
```bash
php artisan scribe:generate
```

## ERD

![ERD](docs/erd.png)

[Lihat versi interaktif di dbdiagram.io](https://dbdiagram.io/d/6a2d20345c789b8acb769f7c)

| Tabel | Keterangan |
|-------|------------|
| `users` | Akun pengguna (bisa jadi penjual & penawar) |
| `auctions` | Data lelang (relasi ke `seller_id`, `winner_id`) |
| `auction_images` | Foto-foto lelang (support multi-upload) |
| `bids` | Riwayat penawaran (relasi ke `auction_id`, `bidder_id`) |