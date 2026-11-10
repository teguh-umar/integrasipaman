# JumpServer Integration Dashboard (`integrasipaman`) — Manual Penggunaan

> Versi didokumentasikan: **4.15.0** (tag `v4.15.0`) · Status: **FINAL dan LIVE di VM GLPI produksi**, cek 1–6 disetujui 2026-11-09 · Terakhir diperbarui: 2026-11-09
>
> **Perubahan penting sejak v4.5.0:**
> 1. Kartu dashboard **JumpServer Session Sharing** sekarang menghitung **orang yang bergabung ke sesi yang dibagikan**, bukan link share yang dibuat. Angka lama dan baru tidak sebanding.
> 2. Tanggal pada layar JumpServer sekarang menunjukkan **waktu kejadian**, bukan waktu impor. Riwayat dapat bergeser setelah upgrade karena waktu JumpServer (+0800) dikonversi ke zona GLPI (Asia/Jakarta, +0700); ini perubahan yang benar, bukan kehilangan atau perubahan kejadian.
> 3. Halaman **Session sharing** kini menampilkan tabel join dan tabel link share secara terpisah. Cron menarik riwayat join satu kali untuk koneksi baru; **Back-fill session joins** di **Setup → Connection** tetap tersedia untuk menarik ulang.
> 4. Jika database PBI tidak dapat dijangkau, **PBI Insights** menampilkan satu baris abu-abu; KPI di atasnya tetap berasal dari event log plugin dan tetap valid.
> 5. **User access** memiliki sub-tab **User**, **Group**, dan **Asset**. Laporan High-risk detail juga menghubungkan command ke sesi yang dibagikan, menampilkan joiner dan pemilik sesi, dan membedakan HIGH command dari sesi bersama dengan akses langsung non-admin.
> 6. Provisioning mengenali Host yang sudah ada di PAMAN, menyediakan pemetaan platform berdasarkan keluarga OS setelah pemetaan OS persis, serta menahan Send bila pemetaan belum lengkap atau Host sudah ada.
> 7. Manual dapat dibuka dari menu **Manual** plugin atau tombol **Manual** di halaman Setup; halaman menunjukkan versi dokumentasi dan peringatan jika manual lebih lama dari plugin.
>
> Sinkronisasi lama Computer → JumpServer (`assetsync`, dulu §5.9) **sudah dihapus total**, diganti provisioning PAMAN yang manual dan butuh persetujuan (§5.9 baru). Status verifikasi per fitur dijelaskan di §10; indikator antrean interim **Already in PAMAN** pada jalur tertentu hanya tercakup oleh test, bukan observasi VM.

---

## 1. Ringkasan

`integrasipaman` (nama tampilan di Setup → Plugins: **JumpServer Integration Dashboard**) menghubungkan GLPI dengan PAM **Fit2Cloud JumpServer** melalui REST API JumpServer (autentikasi Access Key, HTTP Signature HMAC-SHA256). Plugin ini dibuat oleh PGN ICT.

Fungsi utama:

| Fitur | Keterangan singkat |
|---|---|
| **Tarik log audit terjadwal** | Setiap jam menarik *operate-logs*, *login-logs* (hanya login gagal), *session-sharings*, dan catatan orang yang bergabung ke sesi. JumpServer tidak punya webhook, jadi semua data diambil dengan *pull*. |
| **Tarik aktivitas terminal** | Setiap hari menarik record **command** dan **session** terminal JumpServer per bulan, dengan deduplikasi dan retensi (default 13 bulan). Isi *output* command tidak disimpan. |
| **Evaluasi risiko command** | Setiap 15 menit mengklasifikasi setiap command: high-risk atau tidak, kategori (Account Management, Privilege Escalation, Service Change, Network Security, Destructive, General), serta **Priority** (HIGH / MEDIUM / LOW) dan **Catatan**. Untuk HIGH, catatan membedakan command di sesi bersama dari akses langsung non-admin. |
| **Korelasi aset & user** | Mencocokkan string aset JumpServer (`NAMA(IP)`) ke Computer / jaringan produksi GLPI, dan user JumpServer (`Nama(login)`) ke User / Group admin GLPI. Hasil bisa di-*override* manual (dengan batasan — lihat §5.3 dan §10). |
| **[Baru di 3.0.1] Provisioning Computer → PAMAN, dengan persetujuan manusia** | Antrian di halaman *Asset provisioning*: Computer yang layak dikirim ke PAMAN sebagai Host baru menunggu tombol **Send**; tidak ada lagi pengiriman otomatis saat Computer disimpan. Computer yang keluar status aktif ditandai *pending removal* secara otomatis, tetapi penghapusan aktualnya tetap butuh tombol dan nomor tiket yang sudah disetujui. |
| **[Baru di 3.3.0–3.4.2] Entitlement PAMAN ("Who has access")** | Cron mingguan menarik daftar *asset permission rule* JumpServer dan mencocokkannya ke Computer GLPI — termasuk mesin yang belum pernah disentuh siapa pun, bukan hanya yang punya riwayat sesi. Ditampilkan sebagai sub-tab di tab JumpServer access pada Computer. |
| **Laporan & dashboard** | Halaman *JumpServer PBI Insights*, *Session sharing*, *Activity Log*, *Command Audit Report*, dan *User access* dengan sub-tab User/Group/Asset; ekspor tersedia sesuai halaman. Lima kartu dashboard GLPI native (kartu Session Sharing menghitung join) dan tab *JumpServer access* pada form User. |

Kompatibilitas (dari `setup.php`): GLPI 10.0.0 – 11.99.99, wajib ekstensi PHP cURL.

---

## 2. Navigasi & lokasi menu

Mulai **v2.1.0** plugin ini punya **sektor menu sendiri di sidebar**, sejajar dengan Assets / Helpdesk / Management — bukan lagi satu entri di dalam **Tools**. Namanya **JumpServer Integration** (ikon terminal), sama pola dengan `riskregister` dan `drillreport`.

> **Setelah upgrade, logout lalu login lagi.** Sidebar GLPI di-*cache* di session dan dibangun saat login, jadi sektor barunya tidak akan muncul di sesi yang sedang berjalan.

### 2.1 Sektor sidebar **JumpServer Integration**

Sektor ini muncul bila Anda punya **READ pada salah satu** dari tiga hak plugin (§3) — pemegang hanya `plugin_integrasipaman_provisioning` tetap mendapat sektor, isinya hanya entri Asset provisioning.

| Entri sidebar | Halaman | Hak minimum |
|---|---|---|
| **PBI Insights** | `front/pbi.php` (tab *PBI Insights*) | `plugin_integrasipaman_report` **READ** |
| **Command Audit Report** | `front/pbi.php?tab=report` | `plugin_integrasipaman_report` READ |
| **Asset & user correlation** | `front/correlation.php` | `plugin_integrasipaman_config` **UPDATE** |
| **Asset provisioning** [Baru di 3.0.1] | `front/provisioning.php` | `plugin_integrasipaman_provisioning` READ |
| **Setup** | `front/config.form.php` | `plugin_integrasipaman_config` READ (menyimpan perlu UPDATE) |
| **Manual** [Baru di 4.15.0; ikon buku setelah Setup] | `front/manual.php` | READ pada salah satu hak `report`, `config`, atau `provisioning` |

Tanpa satu pun dari ketiga hak plugin, **seluruh sektor disembunyikan** — bukan hanya entrinya.

Entri **Setup** sengaja dipertahankan di sidebar (berbeda dari `drillreport` yang menghapusnya): admin yang punya hak plugin tapi **tidak** punya core `config` tidak bisa membuka Setup → General, sehingga halaman ini satu-satunya jalan mereka ke pengaturan. Admin yang punya core `config` READ otomatis diarahkan ke tab **PGN Suite**.

### 2.2 Tab plugin di form Computer — sekarang di dalam **PGN Suite** [Berubah di 3.0.1]

Sampai v2.7.1, "JumpServer access" dan "PAMAN" adalah dua tab terpisah pada form Computer. Sejak **v3.0.1**, keduanya adalah **sub-tab horizontal di dalam satu tab bernama "PGN Suite"** pada form Computer — bergabung dengan sub-tab plugin PGN lain di aset yang sama (mis. `drillreport`). Ini bukan tab standalone lagi; carilah tab **PGN Suite**, lalu klik salah satu sub-tab berikut:

| Sub-tab di PGN Suite (Computer) | Isi | Hak minimum |
|---|---|---|
| **JumpServer access** | Riwayat sesi & command 30 hari terakhir pada Computer ini, plus sub-tab ketiga **[Baru di 3.3.0–3.4.2] "Who has access"** — lihat §5.10/§5.12 | `plugin_integrasipaman_report` READ |
| **PAMAN provisioning** [Baru di 3.0.1] | Status provisioning Computer ini di PAMAN, dengan tombol Send/Resend/Remove | `plugin_integrasipaman_provisioning` READ |

Sejak v4.13.1, Computer yang cocok dengan Host dalam daftar PAMAN yang sudah ditarik untuk koneksi tersebut diberi keterangan **already in PAMAN as <nama> (<alamat>)** tanpa tombol Send. Sejak v4.14.0, status pemetaan yang belum lengkap ditampilkan sebagai **Eligible - needs mapping** dan Send tidak tersedia sampai pemetaan dilengkapi.

Tab **PGN Suite** itu sendiri hanya muncul bila ada minimal satu plugin PGN yang punya sesuatu untuk ditampilkan pada Computer tersebut untuk pengguna yang sedang login — jadi seorang pengguna tanpa hak apa pun di kedua baris di atas tidak akan melihat tab PGN Suite sama sekali (kecuali plugin PGN lain menyumbang sub-tabnya sendiri).

Tab plugin **User** tidak ikut pindah — lihat 2.3.

### 2.3 Tab **JumpServer access** di form User [Baru di 3.2.0]

Mulai **v3.2.0**, form **User** (bukan Computer) punya tab plugin sendiri bernama **JumpServer access** — tab biasa, bukan bagian dari PGN Suite (kontrak PGN Suite untuk form aset hanya mencakup Computer/NetworkEquipment/Security/Storage; User bukan aset). Menjawab arah sebaliknya dari sub-tab Computer: "orang ini — apa yang dia sentuh?" Isinya dua sub-tab (Sessions, Commands) dan tautan ke Command Audit Report yang sudah difilter ke akun JumpServer orang tersebut. Digerbangi hak `plugin_integrasipaman_report` **READ** — bukan hak untuk membaca User.

### 2.4 Lokasi lain

| Lokasi di UI | Halaman | Siapa yang melihat |
|---|---|---|
| **Setup → General → tab PGN Suite →** bagian *JumpServer Integration* | Pengaturan plugin | Core `config` READ **dan** hak plugin `config` READ |
| **Setup → Plugins → JumpServer Integration Dashboard →** ikon *Configure* | Pengguna dengan core `config` READ diarahkan ke Setup → Tools ICT, bagian JumpServer Integration terpilih; pengguna lain membuka halaman pengaturan plugin | Hak plugin `config` READ |
| **Tools ICT → JumpServer Integration → User access** [Baru di 4.11.0] | Laporan akses JumpServer dengan sub-tab User, Group, dan Asset (`front/useraccess.php`) | Hak plugin `report` READ |
| Tombol **View Activity Log** di halaman Setup | *JumpServer Activity Log* (`front/export.php`, **tab baru**) | Hak plugin `report` READ (tautannya sendiri selalu tampil di halaman Setup, tapi halamannya menolak tanpa hak ini) |
| Link **open list** di halaman correlation | `front/assetmap.php` dan `front/usermap.php` | `config` READ (mengedit satu baris perlu `config` UPDATE) |
| Tombol **Re-evaluate now** di halaman Setup | `front/reeval.php` | `config` UPDATE |
| URL langsung `…/front/backfill.php?month=YYYY-MM` | *JumpServer Terminal Backfill* | `config` UPDATE (tidak ada link menu) |
| URL langsung `…/front/nodemap.php` dan `…/front/platformmap.php` [Baru di 3.0.1] | Daftar pemetaan Computer type → node PAMAN, dan OS → platform PAMAN (mesin pencari GLPI biasa) | `config` READ (tidak ada link menu) |
| **Home / Dashboard →** *Add widget* → grup **JumpServer Integration** | Kartu dashboard native; klik **JumpServer Session Sharing** untuk membuka halaman Session sharing | Hak plugin `report` READ (tanpa itu kartu tidak muncul di daftar) |
| **Setup → Connection → Back-fill session joins** | Menarik riwayat join sesi yang lebih lama dari cursor cron untuk koneksi terpilih | Hak plugin `config` UPDATE |
| **Manual** di menu plugin atau tombol **Manual** di halaman Setup [Baru di 4.15.0] | Membaca panduan penggunaan versi yang terpasang | Hak READ pada salah satu hak plugin `report`, `config`, atau `provisioning` |
| **Setup → Automatic actions** | 5 automatic action plugin (lihat §6) | Admin GLPI |

Tombol *Configure* di **Setup → Plugins** dan entri **Setup** di sektor sidebar JumpServer Integration mengikuti
tujuan yang sama: pengguna dengan hak core Setup (`config` READ) diarahkan ke **Setup → Tools ICT**, bagian
JumpServer Integration terpilih. Pengguna yang hanya memiliki hak plugin `config` READ membuka halaman
pengaturan plugin secara langsung.

**Kenapa Activity Log tidak jadi entri sidebar.** `front/export.php` merender halamannya sendiri (`<!doctype html>` + CSS sendiri, tanpa rangka GLPI) karena dirancang sebagai popup tab baru. Kalau dijadikan entri sidebar, pengguna akan mendarat di halaman tanpa navigasi untuk kembali. Aksesnya tidak berubah: tombol di halaman Setup. Menjadikannya entri sidebar perlu mengubah tampilan itu memakai `Html::header()`/`Html::footer()` lebih dulu — belum dikerjakan.

Catatan: halaman *JumpServer PBI Insights* pada v1.11.x hanya berisi satu tampilan. Sejak v2.0.0 halaman yang sama punya dua tab: **PBI Insights** (default) dan **Command Audit Report**; sejak v2.1.0 kedua tab itu juga punya entri sidebar masing-masing.

---

## 3. Hak akses

Plugin ini punya **tiga hak sendiri**, semuanya di tab **JumpServer Integration** pada **Administration → Profiles**. Sebelum v2.0.0 semua halaman memakai hak core **`config` UPDATE**, sehingga memberi operator SecOps/PAM akses ke laporan otomatis membuka seluruh **Setup → General** GLPI — itulah alasan plugin ini punya hak sendiri sama sekali.

| Hak | Level | Memberi akses ke |
|---|---|---|
| **Configuration** (`plugin_integrasipaman_config`) | Read | Melihat halaman pengaturan dan tabel asset/user mapping |
| | Update | Menyimpan pengaturan, *Re-evaluate now*, backfill bulan, *Back-fill session joins*, korelasi (Re-resolve now), mengedit satu baris mapping |
| **Reports** (`plugin_integrasipaman_report`) | Read (tidak ada Update — laporan tidak punya apa pun untuk diperbarui) | PBI Insights, halaman Session sharing, Command Audit Report + ekspornya, *JumpServer Activity Log* + ekspornya, kartu dashboard JumpServer, sub-tab **JumpServer access** pada Computer **dan** pada User |
| **Asset provisioning** (`plugin_integrasipaman_provisioning`) [Baru di 3.0.1] | Read | Melihat antrian *Asset provisioning* dan sub-tab PAMAN provisioning di Computer — tanpa tombol apa pun |
| | Send to PAMAN (CREATE) | Mengirim/mengirim ulang Computer ke PAMAN |
| | Remove from PAMAN (PURGE) | Menghapus aset dari PAMAN — tetap butuh nomor tiket dalam status yang disetujui (§5.9), memegang hak ini saja tidak cukup |

Mapping (asset & user correlation) sengaja ada di bawah **Configuration**, bukan Reports: mengubah satu baris mengubah apa yang dihasilkan korelasi, jadi itu administrasi, bukan sekadar membaca.

Rinciannya per halaman:

| Aksi / halaman | Hak yang dicek |
|---|---|
| Membuka halaman konfigurasi (jika tidak dialihkan ke bagian PGN Suite) | `plugin_integrasipaman_config` **READ** |
| Menyimpan konfigurasi | `plugin_integrasipaman_config` **UPDATE** |
| Sektor sidebar **JumpServer Integration** | READ pada salah satu dari ketiga hak plugin |
| PBI Insights, Command Audit Report, *JumpServer Activity Log* + ekspornya, unduhan laporan (`front/reportexport.php`) | `plugin_integrasipaman_report` READ |
| Sub-tab **JumpServer access** di Computer dan di User (termasuk "Who has access") | `plugin_integrasipaman_report` READ — **bukan** hak baca Computer/User; siapa pun yang bisa membaca aset atau orangnya belum tentu boleh melihat riwayat/aksesnya |
| Melihat asset mappings / user mappings | `plugin_integrasipaman_config` READ |
| Mengedit satu baris mapping, halaman correlation, Re-evaluate now, backfill | `plugin_integrasipaman_config` UPDATE |
| Kartu dashboard JumpServer | `plugin_integrasipaman_report` READ — tanpa hak ini kartu bernilai 0/kosong dan tidak muncul di *Add widget* |
| Antrian **Asset provisioning** dan sub-tab **PAMAN provisioning** di Computer | `plugin_integrasipaman_provisioning` READ; tombol Send/Resend butuh CREATE, tombol Remove butuh PURGE |
| Tab **PGN Suite** di Setup → General | Core `config` READ untuk membuka tab; bagian JumpServer di dalamnya tetap mengecek `plugin_integrasipaman_config` READ |
| Tab **PGN Suite** di Computer | Muncul bila salah satu sub-tab plugin ini (atau plugin PGN lain) punya sesuatu untuk pengguna tersebut |

**Yang berubah untuk pengguna yang sudah ada saat upgrade dari 2.7.1 atau lebih lama:**
- `plugin_integrasipaman_config` dan `plugin_integrasipaman_report` sudah ada sejak v2.0.0/v2.4.0 — upgrade ke 3.4.2 tidak mengubah keduanya.
- `plugin_integrasipaman_provisioning` adalah hak **baru** di v3.0.1. Ia **tidak** mengikuti mask `config` yang sudah dimiliki profil (berbeda dari cara `report` diseed dulu): kecuali profil tersebut Super-Admin (lihat di bawah), haknya **kosong** sampai admin membukanya sendiri di matriks. Jadi tidak ada operator yang otomatis bisa mengirim/menghapus aset di PAMAN hanya karena upgrade.

**Catatan penting untuk admin:** profil yang punya core `config` UPDATE (biasanya Super-Admin) akan **selalu** dikembalikan ke penuh — Configuration Read+Update, Reports Read, **dan Asset provisioning Read+Send+Remove** — setiap kali `plugin:install --force` dijalankan. Ini disengaja (keputusan pengguna 2026-09-24, "Super-Admin selalu penuh"), agar upgrade tidak pernah meninggalkan instance tanpa seorang pun yang bisa mengelola plugin — termasuk hak provisioning yang menulis ke bastion produksi. Konsekuensinya: menyempitkan hak plugin ini pada profil `config`/UPDATE tidak akan bertahan melewati upgrade berikutnya. Kalau Anda butuh administrator PAM dengan akses terbatas (termasuk provisioning terbatas), gunakan profil yang **tidak** punya core `config` UPDATE — nilai yang Anda atur di matriks pada profil seperti itu akan bertahan melewati upgrade. Gerbang nomor tiket pada penghapusan (§5.9) tetap berlaku untuk Super-Admin juga — memegang PURGE bukan berarti boleh menghapus aset yang tidak dicakup tiket apa pun.

**Lubang keamanan yang ditutup:** sampai v1.11.0, *Activity Log* bisa dibuka user login mana pun (termasuk Self-Service) lewat URL langsung. Ditutup di v1.11.1 (wajib `config` UPDATE) dan sekarang memakai hak `report` READ. Di v2.0.0 kartu dashboard juga ikut dijaga — sebelumnya kartu sama sekali tidak mengecek hak apa pun.

**Tujuan halaman Setup:** tombol *Configure* dan entri **Setup** di sidebar sama-sama mengarahkan pengguna
dengan core `config` READ ke bagian JumpServer di tab **PGN Suite**. Pengguna yang hanya memiliki hak plugin
`config` READ tetap membuka halaman pengaturan plugin secara langsung. Kedua jalur tetap memerlukan hak plugin
`config` READ.

---

## 4. Pengaturan

Buka **Setup → Plugins → JumpServer Integration Dashboard → Configure**. Semua nilai disimpan di tabel konfigurasi core GLPI (`glpi_configs`) dengan context `plugin_integrasipaman`.

> **Keamanan:** Access Key Secret dan PBI DB Password disimpan **tanpa enkripsi** di database GLPI. Jangan menyalin nilainya ke tiket, chat, atau dokumen. Nilai kredensial tidak dicantumkan di manual ini.

### 4.1 JumpServer Integration Settings (koneksi API)

| Label di UI | Kunci config | Default | Arti |
|---|---|---|---|
| JumpServer URL / IP | `jumpserver_url` | kosong | URL dasar JumpServer (tanpa `/api`). Harus URL valid bila diisi. |
| Access Key ID | `jumpserver_access_key_id` | kosong | **Wajib.** ID Access Key JumpServer untuk menandatangani request API. |
| Access Key Secret | `jumpserver_access_key_secret` | kosong | **Wajib.** Secret pasangan Access Key (field bertipe password). |
| Organization ID | `jumpserver_org_id` | ID organisasi *Default* JumpServer | **Wajib.** Dikirim sebagai header organisasi JumpServer. |

Form tidak mau disimpan bila Access Key ID, Access Key Secret, atau Organization ID kosong.

### 4.2 PBI Reporting Database (read-only)

Server MySQL terpisah berisi skema `jumpserver_pbi_views` (dibangun proyek ETL lain), dipakai hanya oleh halaman *PBI Insights*.

| Label di UI | Kunci config | Default | Arti |
|---|---|---|---|
| PBI DB Host | `jumpserver_pbi_db_host` | kosong | Host DB reporting. Jika kosong, halaman PBI Insights menampilkan peringatan "not configured". |
| PBI DB Port | `jumpserver_pbi_db_port` | `3306` | Port MySQL. |
| PBI Database Name | `jumpserver_pbi_db_name` | `jumpserver_pbi_views` | Nama skema. |
| PBI DB Username | `jumpserver_pbi_db_user` | kosong | User read-only. |
| PBI DB Password | `jumpserver_pbi_db_password` | kosong | Password user tersebut. |

Jika database PBI tidak dapat dijangkau, halaman **PBI Insights** menampilkan satu baris abu-abu sebagai pengganti empat kotak error merah. KPI di bagian atas berasal dari event log plugin sendiri, sehingga tetap tersedia dan valid walaupun database PBI sedang tidak terjangkau.

### 4.3 Terminal Command Audit

| Label di UI | Kunci config | Default | Arti |
|---|---|---|---|
| Command Storage ID | `jumpserver_command_storage_id` | kosong | **Wajib agar tarikan terminal berjalan.** ID *command storage* dari JumpServer (Console → Command Storage). Salah ID → command gagal ditarik (HTTP 404) walau session berhasil. |
| Retention (months) | `jumpserver_terminal_retention_months` | `13` | Baris command & session yang lebih tua dari N bulan dihapus di setiap run cron harian (beserta hasil evaluasinya). Rentang 1–60. |
| First-run backfill (months) | `jumpserver_terminal_backfill_months` | `13` | Berapa bulan riwayat yang ditarik. Tidak pernah melebihi nilai retensi. Rentang 1–60. |

Nilai bulan yang tidak valid (≤ 0 / bukan angka) kembali ke 13; nilai di atas 60 dibatasi 60.

### 4.4 Command Risk Evaluation

Tiga textarea, satu entri per baris; baris kosong dan baris diawali `#` diabaikan. **Mengubah daftar mana pun otomatis memicu evaluasi ulang seluruh riwayat** (dikerjakan bertahap oleh cron 15 menit).

| Label di UI | Kunci config | Default | Arti |
|---|---|---|---|
| High-risk keywords | `jumpserver_risk_keywords` | Kata kunci bawaan plugin | Command yang **mengandung** salah satu kata kunci (tidak peka huruf besar/kecil), **atau** risk level JumpServer ≥ 4, ditandai high-risk. Spasi tunggal di akhir kata kunci dipertahankan. |
| Fallback: admin users | `jumpserver_admin_users` | Daftar admin yang dikonfigurasi | Format `Nama Lengkap(login)`, harus sama persis dengan string user JumpServer. **Hanya dipakai bila user tidak ter-resolve lewat Group GLPI.** |
| Fallback: production IP prefixes | `jumpserver_production_ip_prefixes` | Prefix yang dikonfigurasi administrator | Aset yang alamatnya cocok dengan prefix ini dianggap produksi. **Hanya dipakai bila aset tidak ter-resolve** lewat range/network GLPI. |

Aturan Priority/Catatan. Teks catatan untuk dua kondisi HIGH dapat disesuaikan per koneksi di pengaturan evaluasi risiko:

| Kondisi | Priority | Catatan |
|---|---|---|
| Aset bukan produksi | LOW | Aset Non-Production |
| Aset produksi, user admin | MEDIUM | Aktivitas Admin |
| Aset produksi, user bukan admin, command diketik oleh joiner saat bergabung ke sesi milik user lain | HIGH | `Shared session` (default) |
| Aset produksi, user bukan admin, selain kondisi di atas—termasuk command pemilik sesi | HIGH | `Akses Langsung Non-Admin` (default) |

Catatan **Shared session** berarti join masih aktif ketika command dijalankan. Catatan **Akses Langsung Non-Admin** menunjukkan akses yang perlu diperiksa terhadap kebijakan: kemungkinan grant yang keliru, user yang belum dikenali sebagai admin, atau data join yang belum ditarik. Join baru memicu evaluasi ulang command HIGH pada sesi terkait.

Kategori ditentukan oleh peta tetap di kode (bukan config): Account Management, Privilege Escalation, Service Change, Network Security, Destructive; selain itu **General**.

### 4.5 Asset & User Correlation

Resolver berjalan di awal cron *Classify JumpServer command records* dan setiap kali halaman konfigurasi disimpan.

| Label di UI | Kunci config | Default | Arti |
|---|---|---|---|
| Asset match mode | `correlation_match_mode` | `ip_only` | **IP only** — uji alamat yang di-parse dari string aset. **Computer name only** — uji Computer GLPI yang cocok berdasarkan nama. **Hybrid** — alamat hasil parse dulu, lalu Computer yang cocok. **Hybrid (nama dulu)** (`hybrid_name`) — cari Computer GLPI dengan nama yang sama dulu; bila tidak ada, jatuh kembali ke alamat hasil parse. |
| Production IP ranges (Tools > IP Addressing) | `correlation_production_addressings` | kosong | Hanya muncul bila plugin **addressing** aktif. Pilih range IP produksi; aset dengan alamat di dalam range dianggap produksi. Gunakan range yang ditetapkan administrator jaringan. |
| Production networks (core IPNetwork) | `correlation_production_ipnetworks` | kosong | Alternatif: jaringan core GLPI. Biarkan kosong bila memakai range IP Addressing (IPNetwork core sering terisi otomatis oleh inventory). |
| Admin groups | `correlation_admin_groups` | kosong | User GLPI yang menjadi **anggota langsung** grup terpilih dianggap admin resmi. Sub-grup tidak ikut — pilih tiap grup secara eksplisit. |
| *(tersembunyi)* | `correlation_maps_generation` | `0` | Penghitung internal; naik otomatis bila vonis produksi/admin berubah. Tidak diubah manual. |

Pencocokan user: bagian `login` dari string `Nama(login)` dicocokkan ke **login (name) User GLPI** yang tidak terhapus. Pencocokan Computer: IP ke alamat IP Computer, lalu nama ke nama Computer (non-template, tidak terhapus).

Jika semua pilihan korelasi kosong, hasil evaluasi identik dengan memakai daftar fallback saja.

### 4.6 [Baru di 3.0.1] Asset provisioning

Menentukan Computer GLPI mana yang **layak** masuk antrian PAMAN (§5.9). Filter yang dikosongkan tidak memfilter apa pun — pada instalasi baru tanpa filter sama sekali, **setiap** Computer yang punya alamat IPv4 yang bisa dipakai jadi layak.

| Label di UI | Kunci config | Arti |
|---|---|---|
| Eligible computer types | `provisioning_eligible_types` | Computer type yang layak dikirim. Juga sumber node PAMAN: setiap type di sini harus dipetakan ke satu node lewat daftar *Node mapping* (§2.4). Kosong = semua type layak. |
| Eligible statuses | `provisioning_eligible_states` | Status Computer yang layak. Kosong = semua status layak. |
| Dispose statuses | `provisioning_dispose_states` | Computer yang mencapai salah satu status ini (atau dihapus di GLPI) otomatis ditandai **pending removal** di antrian — penandaan otomatis, penghapusan aktualnya tetap manual dan butuh tiket (§5.9). |
| Eligible entities | `provisioning_eligible_entities` | Entitas yang layak. Kosong = semua entitas (termasuk Root Entity). |
| Eligible IP prefixes | `provisioning_ip_prefixes` | Satu prefix per baris. Kosong = semua alamat diterima. |
| Approval ticket statuses | `provisioning_ticket_statuses` | Default **Solved, Closed** (id 5, 6). Status tiket yang dianggap sebagai persetujuan untuk menghapus aset dari PAMAN — tiket yang masih dikerjakan bukan persetujuan. |
| Platforms by OS family | `provisioning_platform_families` | Default: aturan Linux dan Windows (keyword OS bawaan). Satu baris per aturan: `Platform: keyword, keyword`. Aturan bawaan mencakup kata kunci keluarga Linux (mis. linux, ubuntu, debian) dan Windows. Jika pemetaan OS persis tidak ada, aturan pertama dengan keyword yang ditemukan dalam nama OS (tidak peka huruf besar/kecil) dipakai. Platform dicari berdasarkan nama di PAMAN. Kubernetes/SKE tidak disertakan karena merupakan aset Cloud di PAMAN, sedangkan provisioning ini membuat Host. Pastikan nama platform dan kebijakan untuk Windows sesuai lingkungan sebelum mengaktifkan aturan. |

Node PAMAN (per Computer type) dan platform PAMAN (per Operating System) dipetakan di dua daftar terpisah, dibuka lewat URL langsung (§2.4): `front/nodemap.php` dan `front/platformmap.php`. Daftar dapat dicari, difilter, diurutkan, dan diekspor; tombol **Add** tersedia untuk membuat pemetaan. Baris dengan id sumber 0 adalah pemetaan default. Pemetaan platform per keluarga OS dicoba setelah pemetaan OS persis. OS Computer dibaca dari relasi sistem operasi GLPI. Jika node atau platform belum dapat ditentukan, antrean menampilkan **Eligible - needs mapping**, Send dinonaktifkan, dan tautan ke halaman pemetaan tersedia; status simpanan antrean tidak berubah.

---

## 5. Panduan penggunaan

### 5.1 Konfigurasi awal koneksi JumpServer

1. Di JumpServer, siapkan Access Key untuk akun integrasi, catat Organization ID dan Command Storage ID.
2. Buka **Setup → Plugins → JumpServer Integration Dashboard → Configure**.
3. Isi **JumpServer URL / IP**, **Access Key ID**, **Access Key Secret**, **Organization ID**.
4. Di bagian *Terminal Command Audit* isi **Command Storage ID**; sesuaikan **Retention (months)** dan **First-run backfill (months)** bila perlu.
5. (Opsional) Isi bagian *PBI Reporting Database* bila halaman PBI Insights akan dipakai.
6. Klik **Save**. Muncul pesan *Configuration successfully updated.*
7. Pastikan automatic actions aktif dan system cron GLPI berjalan (§6). Tarikan pertama log audit meminta seluruh riwayat yang masih disimpan JumpServer.

Tips: bila browser menawarkan autofill password pada field Access Key Secret, tolak — autofill pernah merusak secret secara diam-diam sehingga JumpServer menolak signature.

### 5.2 Menentukan aset produksi dan admin resmi (korelasi)

1. Buka halaman konfigurasi, bagian **Asset & User Correlation**.
2. Pilih **Asset match mode** (disarankan mulai dengan *IP only*; *Hybrid* bila banyak aset JumpServer tanpa IP di namanya; *Hybrid (nama dulu)* bila nama Computer GLPI yang cocok lebih dapat dipercaya daripada IP, dengan IP tetap sebagai cadangan).
3. Pilih range di **Production IP ranges (Tools > IP Addressing)** (atau **Production networks** bila tidak memakai plugin addressing).
4. Pilih **Admin groups** berisi admin PAM resmi (anggota langsung).
5. Klik **Save** — resolver langsung berjalan (maks. ~10 detik; sisanya diselesaikan cron).
6. Klik **Manage asset & user mappings** untuk melihat ringkasan: total, resolved via GLPI, fallback, manual override, unresolved.

### 5.3 Override manual pemetaan aset atau user

Dipakai misalnya untuk host produksi yang berada di luar segmen produksi, atau user admin yang tidak ada di grup.

1. Buka **Manage asset & user mappings** → **open list** pada *Asset mappings* atau *User mappings*.
2. Cari baris (daftar memakai mesin pencarian GLPI: filter, urut, ekspor CSV/PDF bawaan). Kolom penting: *Asset string / User string*, *Parsed IP / Parsed login*, *Production / Admin*, *Resolved by*, *Manual override*, *Note*, *Last resolved*.
3. Buka baris, pilih **Computer** dan **Classification** (*Production* / *Development*) untuk asset mapping, atau ubah **Admin** untuk user mapping; isi **Note** sebagai alasan, lalu klik **Save**.
4. Mengubah vonis otomatis mengunci baris (**Manual override = Yes**, *Resolved by = manual*); resolver tidak akan menimpanya lagi. Command terkait dievaluasi ulang otomatis oleh cron berikutnya.
5. Untuk mengembalikan ke otomatis, set **Manual override = No** lalu **Save**; resolver akan menurunkan ulang nilai pada run berikutnya.

> **Pemetaan manual di v4.5.0:** form *Asset mappings* menyediakan pilihan **Computer** dan **Classification**. Mengubah salah satunya otomatis mengunci baris agar resolver tidak menimpanya. Untuk kembali ke hasil otomatis, set **Manual override = No** lalu **Save**; resolver akan menurunkan ulang nilai pada run berikutnya.

Nilai *Resolved by*:

| Nilai | Arti |
|---|---|
| `addr_range` | IP berada di range IP Addressing produksi |
| `ipnet` | IP berada di IPNetwork core produksi |
| `computer_ip` | IP Computer GLPI yang cocok berada di jaringan produksi |
| `prefix_fallback` | Tidak ter-resolve via GLPI, tetapi cocok dengan daftar prefix fallback |
| `group` | User anggota Admin groups |
| `name_fallback` | User ada di daftar fallback admin users |
| `manual` | Di-override manual |
| `unresolved` | Tidak ter-resolve (dianggap non-produksi / bukan admin) |

### 5.4 Memaksa re-resolve dan re-evaluasi

- **Re-resolve pemetaan:** halaman *JumpServer correlation* → **Re-resolve now**. Menemukan aset/user baru dan menurunkan ulang semua baris non-manual (maks. ~20 detik).
- **Re-evaluasi risiko:** halaman konfigurasi → **Re-evaluate now** (simpan dulu bila daftar diubah). Berjalan maks. ~20 detik, menampilkan jumlah baris yang dievaluasi dan yang masih tertunda; sisanya diselesaikan cron *Classify JumpServer command records* setiap 15 menit.

### 5.5 Backfill satu bulan data terminal

1. Buka **Setup → Terminal Audit → Backfill a month**.
2. Pilih bulan dari daftar yang tersedia. Bulan yang berada di luar jendela retensi tidak ditawarkan.
3. Hasil berupa tabel Commands/Sessions: *Fetched, Inserted, Skipped, Empty-id, Null-date, Complete, OK*. Data yang sudah ada dilewati, jadi menjalankan bulan yang sama lagi tidak menggandakan data.
4. Bulan dengan volume besar dapat melampaui batas waktu web (~490 detik). Untuk tarikan berat gunakan jalur CLI (§8.4).

Riwayat session join untuk koneksi baru ditarik otomatis satu kali oleh cron. Untuk menarik ulang setelah data dibersihkan, pilih koneksi di **Setup → Connection** lalu klik **Back-fill session joins**. Tindakan ini aman diulang dan hanya berlaku untuk koneksi terpilih (§5.14).

### 5.6 Melihat PBI Insights

1. Sidebar **JumpServer Integration → PBI Insights**.
2. Bagian atas: tiga KPI (Total Events, Login Failures, Session Sharing, 30 hari terakhir) dari log event plugin sendiri.
3. Di bawahnya tabel dari database PBI: **Sessions**, **Commands**, **Login Monitor**, **Session Sharing** — masing-masing maks. 100 baris terbaru dalam 30 hari. Command dipotong 80 karakter di layar.
4. Angka KPI dan tabel bisa berbeda cakupan karena sumbernya berbeda.
5. Jika database PBI tidak dapat dijangkau, tabel diganti satu baris abu-abu, bukan empat kotak merah. KPI di bagian atas tetap valid karena dibaca dari event log plugin, bukan database PBI.

### 5.7 Melihat dan mengekspor Activity Log

1. Halaman konfigurasi → **View Activity Log** (tab baru).
2. Tabel berisi Date, Event, User, Asset, IP Address, Status dari log audit JumpServer (default 30 hari terakhir; maks. 5.000 baris). Date menunjukkan waktu kejadian di JumpServer, dikonversi ke zona waktu GLPI; bukan waktu impor ke GLPI.
3. Gunakan tombol ekspor **CSV**, **Excel**, atau **Print / PDF** (print view → *Save as PDF* di browser).

Jenis event yang disimpan: aksi *operate-logs* (create/update/…), `user_login_failed`, dan `session_share` (status `success` bila aktif, `expired` bila tidak).

### 5.8 Menambahkan kartu JumpServer ke dashboard GLPI

1. Buka dashboard GLPI yang ingin diedit → mode edit → **Add widget**.
2. Pilih grup **JumpServer Integration**: *JumpServer Total Events*, *JumpServer Events by Type - Pie*, *JumpServer Events by Type - Bar*, *JumpServer Login Failures*, *JumpServer Session Sharing*.
3. Kartu **JumpServer Session Sharing** menghitung jumlah join ke sesi yang dibagikan, bukan jumlah link share yang dibuat. Angka pada versi sebelum v4.5.0 mengukur hal berbeda sehingga tidak bisa dibandingkan langsung.
4. Kartu mendukung filter tanggal native dashboard GLPI. Filter memakai waktu kejadian, bukan waktu impor.

### 5.9 [Diganti total di 3.0.1] Provisioning Computer GLPI → PAMAN

Sinkronisasi otomatis lama (`assetsync`, mengirim setiap Computer yang disimpan) **sudah dihapus**. Setiap Computer yang tersimpan/diubah/dihapus hanya memperbarui baris antrian di tabel plugin sendiri — **tidak pernah** menghubungi PAMAN. Mengirim, mengirim ulang, atau menghapus aset di PAMAN selalu perlu tombol yang ditekan manusia di halaman **Asset provisioning** (§2.1) atau di sub-tab **PAMAN provisioning** pada Computer (§2.2).

Status yang mungkin dimiliki satu baris antrian:

| Status | Arti |
|---|---|
| Eligible | Layak (§4.6), belum pernah dikirim |
| Provisioned | Sudah live di PAMAN dan GLPI masih sesuai dengan yang terakhir dikirim |
| Drift | Sudah live di PAMAN, tapi data GLPI sudah berubah sejak terakhir dikirim (name, address, node, atau platform) |
| Pending removal | Computer sudah keluar status aktif (atau dihapus di GLPI) — ditandai otomatis, menunggu penghapusan bertiket |
| Removed | Sudah dihapus dari PAMAN — status akhir, baris tetap disimpan sebagai jejak audit |
| Failed | Percobaan terakhir gagal; selalu bisa dicoba ulang |
| Already in PAMAN | Indikator tampilan (bukan status simpanan antrean) untuk Computer yang identitasnya cocok dengan Host dalam daftar PAMAN yang sudah ditarik untuk koneksi tersebut; tidak dapat dikirim ulang sebagai Host baru |

Baris yang belum memiliki pemetaan node/platform menampilkan **Eligible - needs mapping**. Ini keterangan tampilan; nilai status simpanan antrean tidak berubah. Send tetap dinonaktifkan sampai pemetaan dilengkapi.

Pemeriksaan Host yang sudah ada menggunakan alamat atau nama (tidak peka huruf besar/kecil); alamat menjadi penentu bila nama dan alamat menunjuk hasil berbeda. Daftar Host harus sudah ditarik untuk koneksi/entity yang sesuai. Computer yang cocok ditandai **already in PAMAN** di tabnya dan tidak mendapat tombol Send. Pemeriksaan dilakukan sebelum langkah pemetaan atau pengiriman; plugin tidak mengadopsi Host yang sudah ada dengan mengubah node/platform-nya.

**Mengirim / mengirim ulang (hak CREATE):**
1. Buka **Asset provisioning**, pilih tab status *Eligible*, *Drift*, atau *Failed* (hanya status ini yang menawarkan Send/Resend — *Provisioned* tidak, karena tidak ada transisi provisioned → provisioned). Baris **Already in PAMAN** tidak dapat dipilih.
2. Pilih baris yang dituju lalu klik **Send selected**. Jika pemetaan belum lengkap, lengkapi dari tautan **Node mapping** atau **Platform mapping** yang tersedia (§4.6).
3. Muncul halaman konfirmasi berisi node & platform tujuan setiap aset. Klik **Confirm** untuk lanjut, atau **Cancel**.
4. Hasil ditampilkan satu baris per aset. Host yang sudah ada di PAMAN tidak diadopsi atau diubah oleh proses Send.
5. Alamat yang dikirim dipilih deterministik dari seluruh IPv4 network port Computer (alamat loopback/APIPA dibuang), bukan sekadar baris pertama.

**Menghapus (hak PURGE):**
1. Pada tab *Pending removal*, isi kolom **Ticket #** dengan nomor tiket GLPI yang menyetujui penghapusan, klik **Remove**.
2. Tiket itu harus berstatus salah satu dari **Approval ticket statuses** (§4.6, default Solved/Closed) — tiket yang masih dikerjakan ditolak.
3. Penghapusan selalu satu baris per aksi — tidak ada hapus massal, berbeda dari kirim.

Kegagalan (koneksi PAMAN, node/platform belum lengkap, dsb.) tidak pernah menggagalkan penyimpanan Computer di GLPI — hanya baris antriannya yang menjadi *Failed* dan bisa dicoba ulang.

### 5.10 [Baru di 2.0.0] Command Audit Report

1. Sidebar **JumpServer Integration → Command Audit Report** (atau tab *Command Audit Report* di halaman PBI Insights).
2. Pilih **Month** (daftar bulan yang punya data, terbaru terpilih) atau isi rentang tanggal kustom (*or custom range*), klik **Show**. Rentang kustom yang tidak valid kembali ke bulan default.
3. KPI: *Total commands*, *High-risk* (jumlah dan %), *Users*, *Assets*, *HIGH / MEDIUM / LOW*. Muncul peringatan bila ada command dalam periode yang belum diklasifikasi.
4. **[Baru di 2.3.0]** Keenam laporan berada dalam **satu kartu bertab**, urutannya: *Evaluation summary* (Priority/Catatan/Jumlah, high-risk saja), *Commands per user*, *Commands per asset*, *High-risk by category*, *Top commands* (maks. 100), dan *High-risk detail* paling akhir. Sebelumnya keenamnya bertumpuk ke bawah sepanjang kira-kira enam layar.
5. **[Baru di 2.2.0]** Tiap laporan dipaginasi: kartu ringkasan **15 baris** per halaman, *High-risk detail* **25** baris (sebelumnya 100). Tab yang sedang terbuka ikut tersimpan di URL (`?rtab=`), jadi **menekan Previous/Next atau mengurutkan kolom tidak melemparkan Anda kembali ke tab pertama**.
6. **[Baru di 2.2.0]** Di *Commands per asset* dan *High-risk detail*, nama aset yang sudah ter-korelasi menjadi **tautan ke Computer GLPI**-nya. Aset yang belum ter-korelasi tetap teks biasa, begitu pula aset yang Computer-nya sudah dihapus.
7. **[Baru di 4.12.0]** *High-risk detail* memiliki kolom **JoinedBy** (siapa yang sedang bergabung saat command dijalankan) dan **SharedBy** (pemilik sesi yang dibagikan). Keduanya ikut dalam ekspor. Filter **Sessions that were shared** mencakup command high-risk dari sesi yang pernah memiliki join, termasuk command yang terjadi sebelum atau sesudah masa join.
8. **[Baru di 4.12.0]** Sub-tab **Who joined** di halaman Session sharing memiliki kolom **High-risk** yang menghitung command high-risk selama join aktif. Angkanya membuka *High-risk detail* yang difilter ke sesi itu; ekspor CSV juga menyertakan jumlah tersebut.
9. **[Baru di 4.13.0]** Catatan command HIGH membedakan `Shared session` dari `Akses Langsung Non-Admin` (§4.4). Join yang baru ditarik memicu evaluasi ulang command HIGH dalam sesi terkait.
10. Setiap laporan punya tombol **CSV**, **Excel**, **Print**. **Ekspor selalu mencakup seluruh periode, bukan hanya halaman yang tampil** — paginasi hanya berlaku di tampilan.
11. **Full command log → Download CSV**: seluruh command dalam periode (CSV saja).
12. Nama file: `<YYYYMMDD_YYYYMMDD>_<NAMA_LAPORAN>.csv`. Laporan detail (high-risk dan command log) dibatasi maks. 366 hari. Nilai sel yang diawali `=`, `+`, `-`, `@` diberi awalan `'` untuk mencegah formula injection di Excel.

### 5.11 [Baru di 3.2.0] Melihat riwayat akses seorang user: tab JumpServer access di form User

1. Buka form **User** GLPI orang yang dituju → tab **JumpServer access** (§2.3).
2. Bila user belum ter-korelasi ke akun JumpServer mana pun, tab menampilkan keterangan itu saja dan mengarahkan ke Asset & user correlation.
3. Bila terkorelasi (bisa lebih dari satu akun JumpServer per orang — semuanya dibaca, bukan hanya yang pertama): ringkasan 30 hari terakhir (sesi, aset, command high-risk, gagal), lalu dua sub-tab: **Sessions** dan **Commands** (semua command, bukan hanya yang high-risk — pertanyaan pada tab ini adalah "orang ini melakukan apa", bukan "apa yang salah").
4. Di bawah tabel ada tautan **Open in the Command Audit Report** — satu tautan per akun JumpServer yang terpetakan, sudah difilter ke akun itu di kotak pencarian *High-risk detail* (§5.10 poin 6). Pencarian itu hanya menjangkau command high-risk, bukan semua command.

### 5.12 [Baru di 3.3.0–3.4.2] Melihat siapa yang PUNYA akses ke Computer: sub-tab "Who has access"

Berbeda dari riwayat sesi (§2.2, sub-tab JumpServer access), sub-tab ini menjawab pertanyaan yang berbeda: siapa yang **diizinkan** menjangkau Computer ini, walaupun belum pernah dipakai. Datanya diambil dari cache mingguan (cron `entitlement_pull`, §6) — tab ini tidak pernah memanggil PAMAN secara langsung.

1. Buka form Computer → tab **PGN Suite** → sub-tab **JumpServer access** → sub-tab **Who has access**.
2. Computer yang belum punya riwayat sesi sama sekali (`assetmap` kosong) tetap membuka sub-tab ini secara default bila punya baris entitlement — bukan pesan "not mapped" tanpa isi seperti sebelum v3.4.2 (lihat §8.3).
3. Baris ditampilkan per user/grup: *User/group*, *Type* (user atau group — anggota grup tidak diperluas satu-satu, hanya jumlah anggotanya), *Via* (nama rule JumpServer), *Accounts*, *Actions*, *Status* (**Active**, atau **Expired** dengan tanggalnya).
4. Rule yang "tidak pernah kedaluwarsa" di JumpServer (sentinel tahun 2123) selalu tampil **Active** — lihat §8.3 untuk kenapa nilai itu perlu penanganan khusus.
5. Di atas tabel ada keterangan **"Collected from JumpServer on …"**. Kalau data belum pernah dikumpulkan sama sekali, atau sudah lebih tua dari dua kali interval cron (14 hari), muncul peringatan kuning sebagai gantinya — jangan baca tabel kosong sebagai "tidak ada yang punya akses" tanpa mengecek peringatan ini dulu.

### 5.13 [Baru di 4.5.0] Melihat aktivitas Session sharing

1. Dari kartu **JumpServer Session Sharing** pada dashboard, buka halaman **Session sharing**.
2. Tabel **Who joined a shared session** di bagian atas menunjukkan siapa yang bergabung, alamat asal, metode, waktu masuk/keluar, dan hasilnya. Join yang ditolak ditandai **Refused**; arahkan kursor ke status untuk melihat alasannya. Kolom **High-risk** menghitung command high-risk selama join aktif; klik jumlahnya untuk membuka laporan yang difilter ke sesi tersebut.
3. Tabel **Share links created** di bawahnya tetap menampilkan link yang dibuat. Tabel join dan tabel link share memiliki tombol ekspor **CSV** masing-masing; ekspor join menyertakan kolom **High-risk commands**.
4. Tanggal yang terlihat adalah waktu kejadian JumpServer yang sudah dikonversi ke zona waktu GLPI, bukan waktu data diimpor.

### 5.14 [Baru di 4.5.0] Menarik riwayat join lama

1. Pilih koneksi yang ingin diisi pada **Setup → Connection**.
2. Untuk koneksi baru, cron otomatis menarik riwayat join satu kali. Klik **Back-fill session joins** untuk menarik ulang riwayat setelah data dibersihkan atau bila diperlukan; tindakan ini memerlukan hak **Configuration — Update**.
3. Hasilnya berlaku hanya untuk koneksi yang sedang dipilih. Riwayat yang lebih lama dari cursor tidak ditarik oleh cron reguler.
4. Aman dijalankan ulang: catatan yang sudah tersimpan dilewati dan tidak diduplikasi. Jika proses melaporkan hasil parsial, jalankan lagi sampai selesai.

### 5.15 [Baru di 4.11.0; diperbarui di 4.11.1] Melihat akses JumpServer per User, Group, atau Asset

Buka **Tools ICT → JumpServer Integration → User access**. Halaman ini memiliki tiga sub-tab:

- **User**: satu baris per user JumpServer, dengan User, Username, Group, Asset, Role (system role
  JumpServer), Account, Number of assets, Collected at, dan Entity. Chip Group dan Asset dapat diklik untuk
  membuka daftar lengkap. Tabel mendukung pencarian, pengurutan, dan ekspor seperti daftar GLPI lainnya.
- **Group**: satu baris per group JumpServer, dengan Name, Number of users, Description, Rules, Assets,
  Collected at, dan Entity. Klik angka **Number of users** untuk membuka pop-up berisi anggota group.
- **Asset**: tabel akses per Computer yang sudah ada sebelumnya; filter, pager, dan CSV tetap tersedia.
  Kartu **Groups** di bawah tabel ini telah dihapus di 4.11.1 karena informasi group tersedia di sub-tab
  **Group**.

Data User dan Group dibangun oleh automatic action `entitlement_pull`. Sebelum cron pertama selesai setelah
upgrade, kedua sub-tab menampilkan pemberitahuan bahwa laporan belum dibangun, bukan tabel kosong. Jika data
keanggotaan group sudah lama, ada banner *stale* yang menyertakan tanggal pengumpulan terakhir. Baris dibatasi
sesuai entity koneksi dan hak pengguna.

Saat cron berhasil memperbarui entitlement sebuah asset, baris milik asset yang sama akan dibersihkan bila
Computer GLPI yang tercatat sudah tidak ada lagi di `glpi_computers`. Ini menghindari akses terhitung ganda
ketika Computer dihapus lalu dibuat ulang. Computer di trash, dua Computer yang sama-sama masih ada, dan
asset tanpa Computer pengganti tidak dibersihkan; asset tanpa pengganti tetap dapat tampil sebagai *asset no
longer in GLPI*.

---

## 6. Automatic actions (cron)

Kelimanya terdaftar dengan mode **CLI (external)**, sehingga membutuhkan system cron GLPI di server (di VM: `/etc/cron.d/glpi` menjalankan `front/cron.php` setiap menit).

| Nama crontask (Setup → Automatic actions) | Frekuensi | Apa yang dilakukan |
|---|---|---|
| `pull_historical_logs` (item `PluginIntegrasipamanCron`) — *Pull historical audit logs from the JumpServer REST API* | Setiap jam | Menarik operate-logs, login-logs (gagal saja), session-sharings, dan session-join-records sesuai cursor koneksi. Dedup per ID event JumpServer. Untuk koneksi baru, cron otomatis menarik riwayat join satu kali; untuk menarik ulang atau mengambil data yang dibersihkan, jalankan **Back-fill session joins** per koneksi (§5.14). Dilewati bila URL/Access Key belum diisi. |
| `pull_terminal_activity` (item `PluginIntegrasipamanTerminalPull`) — *Pull JumpServer terminal command & session records, then prune past the retention window* | Harian | Menarik command & session untuk bulan lalu dan bulan berjalan, plus setiap bulan dalam jendela backfill yang masih kosong (self-healing). Lalu menghapus data yang melewati retensi. Dilewati bila Command Storage ID belum diisi. Log run menandai bulan `FETCH FAILED` atau `INCOMPLETE`. |
| `evaluate_commands` (item `PluginIntegrasipamanRiskEval`) — *Classify JumpServer command records (high-risk, category, priority) into the command-eval table* | Setiap 15 menit | 1) Menjalankan resolver korelasi aset/user; 2) mengevaluasi command yang baru atau yang aturannya berubah (maks. ~120 detik per run; sisanya dilanjutkan run berikutnya). Selalu dilaporkan sukses. |
| `provisioning_scan` (item `PluginIntegrasipamanCron`) — *Refresh the PAMAN provisioning queue: eligibility, drift, and assets marked for removal* [Baru di 3.0.1] | Harian | Menyapu seluruh Computer di GLPI (termasuk yang dihapus, untuk menandai *pending removal*) dan menghitung ulang baris antrian: menambah yang baru layak, menghapus yang tidak lagi layak dan belum pernah dikirim, menandai *drift* bila data berubah, menandai *pending removal* bila Computer keluar status aktif. **Tidak pernah menghubungi PAMAN** — cron ini hanya menyiapkan pekerjaan untuk manusia. |
| `entitlement_pull` (item `PluginIntegrasipamanEntitlement`) — *Collect JumpServer asset permissions (who may reach which asset)* [Baru di 3.3.0; diperluas di 4.11.0] | Mingguan | Menarik seluruh *asset permission rule* JumpServer beserta anggotanya (user/grup), lalu mencocokkan setiap Computer yang namanya/alamatnya cocok dengan host JumpServer (bukan hanya yang punya riwayat command) ke rule yang menjangkaunya. Sejak 4.11.0, run juga menarik semua user JumpServer beserta system role-nya, meminta daftar rule yang menjangkau setiap host JumpServer, lalu membangun ulang data sub-tab User dan Group. Kegagalan pada salah satu langkah baru dicatat di log dan tidak menghentikan run. Pada verifikasi deployment, satu run memerlukan sekitar **7 menit 15 detik**. Dibatasi 700 Computer per run (§10); yang belum sempat diproses didahulukan pada run berikutnya (paling lama belum diperbarui, lalu yang belum pernah sama sekali). Kegagalan pada satu Computer tidak menghapus cache lamanya. |

---

## 7. Integrasi dengan plugin lain

### 7.1 JumpServer (sistem eksternal)

| Arah | Endpoint JumpServer | Dipakai oleh |
|---|---|---|
| JumpServer → GLPI | `/api/v1/audits/operate-logs/`, `/api/v1/audits/login-logs/`, `/api/v1/terminal/session-sharings/` | cron `pull_historical_logs` |
| JumpServer → GLPI | `/api/v1/terminal/session-join-records/` | cron `pull_historical_logs`; riwayat sebelum cursor melalui **Back-fill session joins** |
| JumpServer → GLPI | `/api/v1/terminal/commands/`, `/api/v1/terminal/sessions/` | cron `pull_terminal_activity`, halaman backfill |
| GLPI → JumpServer | API Host PAMAN | Hanya saat operator secara manual mengonfirmasi **Send**, **Resend**, atau **Remove** pada antrian provisioning (§5.9); menyimpan Computer di GLPI tidak mengirimnya |

Semua request memakai Access Key (HTTP Signature) dan header organisasi dari konfigurasi.

### 7.2 Plugin GLPI lain

| Plugin | Hubungan | Status |
|---|---|---|
| **addressing** (Tools → IP Addressing) | Dibaca (read-only) sebagai sumber range IP produksi untuk korelasi. Opsi hanya tampil bila plugin aktif. | Aktif, dipakai di VM |
| **glpiinventory** (core Computer/IPAddress) | Tidak dipanggil langsung; data Computer & IP hasil inventory dipakai resolver. | Tidak langsung |
| **riskregister** | Rencana: command high-risk dengan priority HIGH menjadi `Finding` di Risk Register. | **Belum dibangun** |
| Database **PBI** (`jumpserver_pbi_views`, proyek ETL terpisah) | Dibaca read-only oleh halaman PBI Insights. | Aktif bila dikonfigurasi |
| Tab bersama **PGN Suite** di Setup → General [Baru di 2.1.0] | Bagian pengaturan plugin ini jadi satu sub-nav di tab PGN Suite, di samping plugin PGN lain. | Aktif, dipakai di VM |
| Tab bersama **PGN Suite** di form Computer [Baru di 3.0.1] | Sub-tab *JumpServer access* dan *PAMAN provisioning* jadi sub-tab horizontal di tab PGN Suite Computer, di samping sub-tab plugin PGN lain (mis. `drillreport`). Lihat §2.2. | Aktif, dipakai di VM |

Plugin tidak menulis ke tabel plugin lain.

---

## 8. Instalasi & upgrade

### 8.1 Paket

- Zip bernama `integrasipamanv<versi>.zip` (terbaru: `integrasipamanv4.15.0.zip`), root arsip = folder `integrasipaman/`. Paket 4.15.0 menyertakan `docs/manual.md` untuk menu Manual; folder `tests/` tidak disertakan.
- Tujuan di server: `/data/www/glpi/plugins/integrasipaman`.

### 8.2 Instalasi baru

1. Upload zip ke VM GLPI tujuan melalui jalur transfer yang disetujui administrator.
2. Ekstrak di `/data/www/glpi/plugins/`, lalu `sudo chown -R nginx:nginx /data/www/glpi/plugins/integrasipaman`.
3. Jalankan:
   - `sudo -u nginx php /data/www/glpi/bin/console plugin:install integrasipaman --force`
   - `sudo -u nginx php /data/www/glpi/bin/console plugin:activate integrasipaman`
4. Instalasi membuat tabel data plugin, mendaftarkan lima automatic action, membuat hak `plugin_integrasipaman_config`, dan mengisi nilai default config.
5. Beri hak ke profil yang perlu di **Administration → Profiles → JumpServer Integration** (§3).
6. Isi konfigurasi (§5.1).

### 8.3 Upgrade

1. Backup folder plugin lama (mis. `sudo cp -a …/integrasipaman …/integrasipaman.bak-v<lama>`).
2. Ekstrak zip baru menimpa folder, `chown` ke `nginx`, cek versi di `setup.php`.
3. Restart/reload php-fpm (OPcache).
4. Jalankan `plugin:install integrasipaman --force` (atau tombol upgrade di Setup → Plugins bila muncul "requires update"), lalu `plugin:activate integrasipaman`.
5. Upgrade bersifat aman: tabel dibuat hanya bila belum ada, kolom lama dimigrasi di tempat, dan config yang sudah diisi admin **tidak ditimpa** (hanya kunci baru yang ditambahkan).
6. Cek `/data/www/glpi/files/_log/php-errors.log` dan `sql-errors.log`.
7. **Logout lalu login lagi** — menu sidebar di-cache di session, jadi entri menu tidak berubah sebelum sesi baru.
8. Untuk koneksi baru, cron menarik riwayat join satu kali. Bila data join perlu dipulihkan atau ditarik ulang, gunakan **Setup → Connection → Back-fill session joins** untuk koneksi terkait (§5.14).

> **Perbaikan skema yang tidak terlihat operator (v3.2.0, v3.4.1).** v3.2.0 memperbaiki dua kolom `*_id` yang seharusnya UNSIGNED, dan v3.4.1 memastikan kolom tanggal pada tabel entitlement (`permrules.date_start`/`date_expired`) tetap TIMESTAMP, bukan DATETIME. GLPI 11 dapat menampilkan peringatan skema untuk kolom DATETIME pada tabel plugin; perubahan ini juga menjaga cron entitlement tetap berjalan untuk tanggal berakhir yang jauh di masa depan (§10). Tidak ada langkah operator untuk perbaikan ini.

> **Segera setelah naik ke v3.4.2, jangan kaget melihat entitlement bernilai 0.** Pembersihan cache satu-kali dari migrasi v3.4.1 (lihat di atas) juga mereset jadwal cron `entitlement_pull`, sehingga `glpi_plugin_jumpserver_permrules` dan `glpi_plugin_jumpserver_assetperms` sempat terbaca **0 baris** tepat setelah upgrade — itu normal, bukan kegagalan. Begitu automatic action berikutnya jalan (menit berikutnya bila system cron aktif), keduanya terisi ulang dan sub-tab **Who has access** (§5.12) kembali menunjukkan data.

> **Khusus upgrade ke v2.0.0 (perubahan hak akses).** Sebelum upgrade, catat profil mana saja yang punya core `config` UPDATE:
> `SELECT p.id, p.name FROM glpi_profilerights pr JOIN glpi_profiles p ON p.id = pr.profiles_id WHERE pr.name = 'config' AND (pr.rights & 2);`
> Setelah upgrade, profil-profil itu harus muncul dengan Read + Update tercentang di tab **JumpServer Integration**. Installer menulis ke `glpi_profilerights`, jadi ambil backup DB dulu.

> **Jangan Uninstall untuk upgrade.** Uninstall tidak menghapus tabel data, tetapi **menghapus seluruh nilai konfigurasi** (termasuk Access Key dan password DB PBI) dan mendaftarkan ulang cron. Setelah reinstall, run pertama menarik ulang seluruh riwayat dan dedup melewati data yang sudah ada.

### 8.4 Tarikan berat lewat CLI

Batas waktu request web ~490 detik. Backfill besar, run pertama `pull_terminal_activity`, atau evaluasi ulang ratusan ribu baris jangan dijalankan via tombol **Execute** di browser. Gunakan:

- `sudo -u nginx php /data/www/glpi/front/cron.php <N>` (N = jumlah task yang dijalankan), di background dengan `nohup`/`setsid` (tidak ada tmux/screen di VM).
- `front/cron.php` menerima **angka**, bukan nama task. Task yang dijalankan adalah task yang sudah jatuh tempo.
- Evaluasi ulang besar (mis. setelah daftar keyword diubah) cukup dibiarkan diselesaikan cron 15 menit secara bertahap.

---

## 9. Troubleshooting & FAQ

| Gejala | Kemungkinan penyebab → solusi |
|---|---|
| Sektor sidebar **JumpServer Integration** tidak muncul | Tiga sebab, cek berurutan: (1) belum logout/login setelah upgrade — sidebar di-cache di session; (2) profil tidak punya `plugin_integrasipaman_config` READ (Administration → Profiles → tab JumpServer Integration); (3) plugin tidak aktif. |
| Simpan konfigurasi gagal: *Access Key ID, Access Key Secret and Organization ID are all required.* | Salah satu field wajib kosong → isi ketiganya. |
| *JumpServer URL / IP is not a valid URL.* | Isi URL lengkap dengan skema (`https://…`), tanpa `/api`. |
| Log cron: *JumpServer URL/IP or Access Key ID/Secret is not configured. Skipping.* | Lengkapi koneksi di halaman konfigurasi. |
| Log cron terminal: *… Command Storage ID not configured. Skipping.* | Isi **Command Storage ID**. |
| Commands `FETCH FAILED` (HTTP 404) tetapi sessions OK | Command Storage ID salah → ambil ID yang benar dari JumpServer (Console → Command Storage). |
| Semua fetch gagal / JumpServer menolak signature | Access Key Secret salah atau rusak karena autofill browser → ketik ulang secret, simpan; periksa Organization ID dan jam server (header Date ikut ditandatangani). |
| Log cron menandai bulan `INCOMPLETE` | Tarikan terpotong batas halaman → jalankan ulang backfill bulan itu (`backfill.php?month=YYYY-MM`) atau biarkan cron berikutnya. |
| Tombol **Execute** di Automatic actions timeout | Batas ~490 detik → jalankan via CLI (§8.4). |
| Automatic action tidak pernah berjalan | Mode task CLI dan system cron tidak ada/mati → pastikan `/etc/cron.d/glpi` aktif. |
| Laporan menunjukkan command *awaiting classification* / Re-evaluate menyisakan baris *still stale* | Normal setelah perubahan aturan atau data baru → tunggu cron 15 menit menyelesaikan. |
| Priority HIGH/MEDIUM/LOW bergeser setelah mengubah korelasi | Perilaku yang diharapkan (aset/user direklasifikasi). Jumlah high-risk sendiri tidak berubah. |
| Host produksi di segmen non-produksi terbaca LOW | Buat override manual di Asset mappings (§5.3). |
| User admin terbaca bukan admin | Pastikan login GLPI sama dengan login di JumpServer dan user adalah anggota **langsung** Admin groups, atau override di User mappings. |
| Opsi *Production IP ranges (Tools > IP Addressing)* tidak ada | Plugin addressing tidak aktif → pakai *Production networks* atau aktifkan addressing. |
| PBI Insights: *PBI reporting database is not configured* / *Could not connect…* | Jika database belum dikonfigurasi atau tidak terjangkau, PBI Insights menampilkan satu baris abu-abu; KPI event log plugin di atasnya tetap valid. Untuk melihat tabel PBI, isi atau perbaiki bagian PBI Reporting Database dan pastikan server dapat dijangkau dari VM. |
| Computer tidak muncul/terkirim di PAMAN | Sinkronisasi otomatis lama sudah dihapus (§5.9) — cek antrian **Asset provisioning**: apakah Computer itu *Eligible* (§4.6) sama sekali, dan apakah sudah ditekan **Send**. |
| Data historis hilang setelah reinstall? | Tidak — tabel dipertahankan. Yang hilang adalah konfigurasi; isi ulang. |
| Automatic action ditandai berhasil (*Last run* normal) tetapi datanya jelas tidak berubah | GLPI menandai *Last run* meskipun task tersebut melempar error di tengah jalan. Cek `files/_log/cron.log` untuk baris `Error during <task> execution`, lalu `files/_log/php-errors.log` untuk sebab aslinya. Pertimbangkan mengaktifkan notifikasi error cron bawaan GLPI (Setup → Notifications) supaya kegagalan seperti ini tidak baru diketahui saat seseorang membaca datanya. |
| Asset mapping tertaut ke Computer yang salah | Buka barisnya di *Asset mappings*, pilih Computer yang benar, lalu simpan. Di v4.5.0 perubahan ini mengunci baris agar resolver tidak menggantinya; untuk mengaktifkan pemetaan otomatis lagi, set **Manual override = No** (§5.3). |
| Satu asset string ter-tautkan otomatis ke Computer yang salah, padahal namanya cocok dengan Computer lain | Kemungkinan dua Computer GLPI berbagi satu IP — resolver berbasis IP memilih id Computer terkecil tanpa memeriksa nama aset (§10). Di v4.5.0, pilih Computer yang benar secara manual pada *Asset mappings* (§5.3); atau bersihkan duplikasi IP di GLPI agar pemetaan otomatis juga benar. |
| Sub-tab **Who has access** menunjukkan peringatan basi ("may be out of date") | Cron `entitlement_pull` belum jalan dalam 14 hari terakhir untuk Computer itu, atau belum pernah sama sekali. Cek Setup → Automatic actions untuk task `entitlement_pull`, dan lihat §10 untuk batas ukuran estate yang bisa ditangani satu run. |

---

## 10. Status & batasan

### Status versi dan verifikasi

- **v4.15.0 FINAL dan LIVE di VM GLPI produksi**, disetujui melalui cek 1–6 pada 2026-11-09. Rilis ini menambahkan halaman Manual dari menu plugin dan tombol di Setup; versi manual ditampilkan, dengan peringatan bila dokumentasi lebih lama dari versi plugin.
- **v4.14.0**: cek 1–9 disetujui di VM pada 2026-11-09, termasuk pemetaan platform keluarga OS dan penolakan Send untuk Host yang sudah ada. Tampilan interim baris antrean **Already in PAMAN** tanpa checkbox (cek 10) tidak teramati di VM karena baris sudah terhapus pada scan; jalur tersebut diverifikasi melalui test saja.
- **v4.13.1** mengenali Host yang sudah ada berdasarkan alamat atau nama (tidak peka huruf besar/kecil), menggunakan daftar Host yang telah ditarik untuk koneksi terkait. Bila alamat dan nama menghasilkan kecocokan berbeda, alamat menjadi penentu.
- **v4.13.0** membedakan HIGH command `Shared session` dari `Akses Langsung Non-Admin`; join baru memicu evaluasi ulang. **v4.12.0** menambahkan informasi joiner dan pemilik sesi pada High-risk detail serta jumlah command high-risk per join.
- **v4.11.0–4.11.1** menambahkan laporan akses User/Group/Asset dan memperbaiki pembersihan entitlement lama; **v4.5.1** menambahkan pemilih bulan untuk backfill terminal dan tarikan otomatis satu kali untuk riwayat join koneksi baru.
- Perubahan waktu dari waktu impor ke waktu kejadian pada rilis v4.5.0 membuat tanggal historis dapat bergeser setelah upgrade; itu perubahan yang diharapkan, bukan kehilangan kejadian (§5.7, §5.13–§5.14).

### Batasan dan hal yang perlu diperhatikan

- Aturan platform per keluarga OS dicoba setelah pemetaan OS persis. Konfigurasi Windows harus disesuaikan dengan nama platform PAMAN yang tersedia dan kebijakan lingkungan; jangan mengasumsikan nama platform tertentu.
- Pemeriksaan **Already in PAMAN** bergantung pada daftar Host yang sudah ditarik untuk koneksi/entity yang tepat. Pastikan entitlement telah dikumpulkan sebelum mengandalkan hasil pencocokan; baris yang sudah tampil **Already in PAMAN** tidak dapat dikirim.
- Host yang node atau platform-nya belum dapat ditentukan belum bisa dikirim. Lengkapi pemetaan dahulu; **Eligible - needs mapping** adalah keterangan tampilan, bukan status antrean baru.
- Kapasitas `entitlement_pull` dibatasi 700 Computer per run. Computer yang belum diproses menunggu run berikutnya; data entitlement yang belum berhasil diperbarui dapat menjadi basi dan memunculkan peringatan setelah 14 hari.
- Kesalahan pada satu baris data JumpServer dapat menghentikan satu run entitlement. Jika automatic action tampak berhasil tetapi data tidak berubah, periksa log cron dan PHP di §9.
- Dua Computer GLPI yang berbagi alamat dapat menyebabkan resolver otomatis memilih Computer yang tidak diharapkan. Periksa dan perbaiki *Asset mappings* secara manual (§5.3).
- Access Key Secret dan password DB PBI disimpan tanpa enkripsi di `glpi_configs`. Jangan menyalin nilainya ke tiket, chat, atau dokumen.
- Jika endpoint JumpServer mengabaikan filter tanggal, plugin menyaring ulang data di sisi GLPI. Ini menjaga isi tabel sesuai periode yang diminta, tetapi dapat meningkatkan volume transfer.
- Isi output command tidak disimpan; teks command panjang dapat dipotong. Kategori risiko ditentukan kode, sementara keyword, fallback admin, dan prefix produksi dapat dikonfigurasi.
- Grup admin tidak mewarisi sub-grup. Data entitlement untuk grup menampilkan jumlah anggota, bukan rincian akses per anggota pada tab aset.
- Backfill lewat web dibatasi waktu request. Untuk tarikan besar atau evaluasi ulang banyak baris, gunakan cron CLI sesuai §8.4.
- Integrasi otomatis command HIGH menjadi `Finding` di Risk Register belum tersedia. Uninstall menghapus konfigurasi plugin, sementara tabel data tetap dipertahankan.
