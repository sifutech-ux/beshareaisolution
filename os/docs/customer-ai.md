# AI Auto Reply untuk semua perniagaan

Pembukaan ini menggantikan had satu perniagaan pilot dengan kelayakan untuk setiap
perniagaan aktif. Pemilik pelanggan masih meluluskan pengetahuan, skop penerima dan
had harian sendiri. Panel Pemilik boleh menghidupkan tetapan yang sudah diluluskan.

## Perilaku pelanggan

1. Sambungkan dan lengkapkan pendaftaran nombor WhatsApp.
2. Sediakan draf pengetahuan, semak dan terbitkan. Pilihan nombor untuk penerbitan
   pertama tersedia secara berasingan daripada kebenaran menggunakan AI.
3. Pilih **Semua pelanggan yang menghubungi nombor perniagaan**, tetapkan had
   jawapan sehari, sahkan pengetahuan dan skop, kemudian hidupkan AI dan simpan.
4. Mesej baharu selepas pengaktifan diproses mengikut pengetahuan yang diluluskan.
   Mod ambil alih manusia, had harian, keadaan sambungan dan tetingkap balasan
   sedia ada terus dikuatkuasakan.

Mod **Nombor tertentu untuk ujian** kekal pilihan. Tetapan lama mengekalkan mod ini
sehingga pemilik mengesahkan perubahan skop. Akaun baharu memilih skop semua
pelanggan dalam borang, tetapi tiada AI dihidupkan tanpa penyimpanan dan persetujuan
pemilik. Penerbitan pengetahuan tidak menghidupkan AI atau memperluas penerimanya.

## Konfigurasi pelayan

Fail konfigurasi persendirian yang dibaca `PrivateConfig` menerima:

```php
'ai_customer_rollout' => 'all',
'ai_enabled' => 'true',
```

`ai_enabled` ialah suis utama. Jika kunci itu belum wujud, `owner_ai_enabled`
digunakan untuk keserasian pemasangan terdahulu. Tanpa nilai tepat `all`, kelayakan
pilot `owner_ai_business_id` masih terpakai. Nilai rahsia penyedia dan kunci
penyulitan sedia ada dikekalkan; tiada rahsia disalin ke repository atau log.

## Pemasangan

Gunakan commit yang disemak pada `beshareaisolution.com` dan aliran Git Hostinger
sedia ada. Sandarkan pangkalan data, konfigurasi peribadi dan jadual cron dahulu.
Kod baharu menyokong pilot sebelum migrasi; jangan buka rollout sebelum skema siap.

```sh
php os/bin/prepare-customer-ai.php
php os/bin/prepare-customer-ai.php --apply --checksum=CHECKSUM_DARI_SEMAKAN
```

Migrasi `0007_customer_ai.up.sql` hanya menambah skop dan persetujuan penerima pada
`ai_reply_settings` serta rekod giliran `ai_reply_dispatch_state`. Ia tidak mengubah
fakta, status AI, penerima, kuota, sambungan Meta atau akses pengguna sedia ada.
Pemasangan separa dihentikan untuk semakan; tiada pemadaman atau ulangan foundation.

Selepas pemasangan, tetapkan dua kunci konfigurasi di atas. Semak tanpa menghantar:

```sh
php os/bin/process-ai-replies.php --all --inspect
```

Tukar **hanya** jadual pemproses AI yang menggunakan `--business=ID` kepada:

```sh
php /ABSOLUTE/WEBROOT/os/bin/process-ai-replies.php --all --limit=10 --business-limit=20
```

Kekalkan pelaksana PHP, sela jadual dan redirection log persendirian yang sudah
digunakan. Elakkan jadual pilot dan `--all` berjalan serentak. Kunci perniagaan
mencegah dua pekerja memproses perniagaan sama; kunci dispatcher mencegah pertindihan
jadual semua pelanggan. Giliran disimpan selepas setiap perniagaan dilawati supaya
akaun kemudian tidak kelaparan ketika had masa atau bilangan dicapai. Setiap
perniagaan mengekalkan kuota dan kunci sendiri. `--inspect` tidak mengubah giliran,
menuntut kerja, menjana AI atau menghantar mesej.

## Penerimaan dan semakan balasan

Callback aplikasi sedia ada `/webhook/index.php` menerima mesej bertandatangan
bagi pasangan WABA dan nombor yang sudah dipadankan tepat kepada perniagaan aktif.
Resit penghantaran bagi sambungan yang diketahui turut direkodkan selepas akaun
digantung atau sambungan diputuskan, tanpa memberi kebenaran menghantar semula.
Pemilikan sambungan disemak semula di bawah kunci transaksi sebelum penyimpanan.
Laluan owner lama dan callback terus menyahganda peristiwa menggunakan kunci sama.
Peristiwa lain kekal pada pengendali sedia ada. Tiada perubahan callback Meta atau
pendaftaran nombor dijalankan oleh pemasangan ini.

Sebelum menjana dan menghantar, pekerja menyemak semula perniagaan aktif, akses
platform, sambungan, persetujuan pemilik semasa, skop, semakan fakta, pengambilalihan
manusia, waktu mesej dan kuota. Persetujuan semua pelanggan mempunyai pemilik dan
cap masa tersendiri. Pemindahan pemilikan atau penerbitan semula pengetahuan tidak
mewariskan persetujuan penerima daripada pemilik lama. Penghantaran tidak pasti
tidak dicuba semula secara automatik.

## Pengunduran

Tetapkan `ai_enabled` kepada `false` dan hentikan jadual AI untuk menghentikan kerja
baharu. Jangan padam skema atau data. Untuk kembali kepada pilot, hentikan jadual
`--all`, pulihkan konfigurasi dan jadual yang disandarkan, dan semak skop penerima
owner sebelum menyambung. Tetapan `all` tidak dibalas oleh pekerja pilot. Panggilan
penyedia yang sudah dihantar tidak boleh ditarik balik.

## Pengesahan khusus

Workflow `Customer AI production boundaries` menggunakan MySQL 8.4, PHP 8.3 dan
fixture sintetik berpagar loopback. Ia menyemak migrasi dan pemeliharaan tetapan,
persetujuan pemilik, pemisahan perniagaan, callback bertandatangan, penerimaan
berulang, giliran kerja, had kuota, aliran penerbitan dan kawalan UI. Tiada API
penyedia atau nombor pelanggan sebenar digunakan dalam ujian.
