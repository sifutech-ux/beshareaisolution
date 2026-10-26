# Panel Pemilik BeShare AI OS

Panel ini menambah pengurusan pelanggan pada versi selepas PR #72. Ia menggunakan akaun OS sedia ada, dengan pemberian akses platform yang berasingan daripada peranan `owner`, `admin` atau `staff` dalam perniagaan pelanggan.

## Laluan dan fungsi

- Panel: `/whatsapp/pemilik/`.
- API: `/whatsapp/pemilik/pintu.php`.
- Akaun yang diberi akses melihat pautan **Panel Pemilik** dalam ruang perniagaan.
- Destinasi log masuk yang dibenarkan ialah `/whatsapp/ruang.html?tujuan=pemilik#masuk`. Selepas log masuk, hanya pengguna dengan grant platform sah dihantar ke panel tetap ini.
- Senarai pelanggan memaparkan satu baris bagi setiap ruang perniagaan, pemilik, nombor, kesediaan sambungan, akses BeShare, status AI, metadata penerbitan pengetahuan dan aktiviti terakhir. Tiada pelan berbayar atau hasil jualan direka; modul bil belum disambungkan.
- Carian, penapis dan pagination dilakukan di pelayan. Semua masa API ialah UTC ISO 8601; UI memaparkan waktu Malaysia.

## Tiga kawalan berasingan

| Kawalan | Tingkah laku |
|---|---|
| Akses BeShare | Menulis `platform_business_access`; penggantungan mematikan AI dan dikuatkuasakan pada API pelanggan, media pengetahuan, jambatan meja lama dan pemproses AI/Coexistence. |
| Pengaktifan WhatsApp | Menyemak sambungan tersimpan bagi pelanggan/nombor tertentu; mendaftarkan nombor baharu yang layak atau menyelaraskan status nombor yang sudah berdaftar. |
| AI Auto Reply | Menghidupkan/mematikan tetapan yang diluluskan pemilik pelanggan. Rollout `ai_customer_rollout=all` membuka semua perniagaan selepas migrasi 0007; suis pelayan, skop penerima, persetujuan pemilik dan kuota tetap berkuat kuasa. Lihat [AI pelanggan](customer-ai.md). |

Mengaktifkan semula akses BeShare tidak menghidupkan AI secara automatik. Menggantung akses tidak memadam WABA, memutuskan nombor atau menukar pemilikan pelanggan. Tindakan yang telah dihantar kepada penyedia sebelum penggantungan tidak boleh ditarik balik; semakan sebelum panggilan seterusnya kekal berkuat kuasa.

## Pemasangan selepas kelulusan deployment

Semua arahan di bawah ialah langkah deployment yang perlu dijalankan pada persekitaran yang telah diluluskan. Tiada skrip ini dipanggil daripada halaman web, CI atau permintaan pelanggan.

1. Sediakan sandaran mengikut prosedur operasi sedia ada. Gunakan fail daripada commit PR yang telah diluluskan; jangan gabungkan PR lain sebagai sebahagian pemasangan ini.
2. Jalankan semakan migrasi tambahan:

   ```bash
   php os/bin/prepare-platform-admin.php
   ```

3. Gunakan checksum sebenar yang dipulangkan oleh semakan:

   ```bash
   php os/bin/prepare-platform-admin.php --apply --checksum=CHECKSUM_DARIPADA_SEMAKAN
   ```

Migrasi `0006_platform_admin.up.sql` hanya menambah `platform_admins`, `platform_business_access` dan `platform_whatsapp_operations`. Ia tidak menjalankan semula foundation, mendaftarkan nombor, menghantar mesej, atau memberikan akses kepada sesiapa. Pemasangan separa dihentikan untuk semakan; tiada pemadaman automatik.

4. Danial dan Dato Anuar perlu menggunakan akaun individu. Kenal pasti e-mel akaun OS yang memang dimaksudkan sebelum pemberian akses. Contoh berikut menggunakan placeholder:

   ```bash
   php os/bin/platform-admin.php --email='pemilik@example.com'
   php os/bin/platform-admin.php --email='pemilik@example.com' --user-id=ID_DARIPADA_SEMAKAN --grant --apply
   ```

E-mel dan ID mesti sepadan. Tiada e-mel pemilik dikodkan dalam repository dan tiada kata laluan lalai dicipta. Setiap pemberian atau penarikan akses direkodkan sebagai tindakan sistem melalui CLI.

Untuk menarik akses:

```bash
php os/bin/platform-admin.php --email='pemilik@example.com'
php os/bin/platform-admin.php --email='pemilik@example.com' --user-id=ID_DARIPADA_SEMAKAN --revoke --apply
```

Sesi sedia ada diperiksa terhadap grant aktif dan status pengguna pada setiap permintaan admin. Peranan dalam `/admin/` lama tidak memberikan akses platform ini.

## Pengaktifan nombor

Butang **Kemas kini status** menggunakan bacaan Meta sahaja dan menyimpan hasil semakan. Ia tidak mendaftarkan nombor atau menghantar mesej.

Butang **Lengkapkan pengaktifan WhatsApp** memerlukan:

- Pengguna platform sah dan perniagaan aktif.
- Pelanggan, sambungan, nombor paparan dan aset WABA yang sepadan dengan rekod tersimpan.
- Token yang masih sah untuk aplikasi ini; akses kepada aset yang tepat.
- Nombor milik WABA yang disahkan, pemilikan nombor berstatus `VERIFIED`, dan aplikasi telah dilanggan pada WABA.
- Nombor baharu berstatus `PENDING`, `is_on_biz_app=false`, serta `platform_type` yang dikenali sebagai `CLOUD_API` atau `NOT_APPLICABLE`.
- Rekod Embedded Signup standard yang telah selesai untuk pemilik pelanggan semasa dan aset yang sama, dalam tetingkap pendaftaran Meta.

`NOT_APPLICABLE` diterima hanya bersama semua bukti nombor baharu di atas. `ON_PREMISE`, jenis yang tiada atau tidak dikenali dan nombor Coexistence tidak didaftarkan melalui tindakan ini. Nombor import lama boleh disemak statusnya; import itu sendiri bukan bukti kebenaran pendaftaran baharu.

Jika Meta mengesahkan nombor sudah `CONNECTED`, status dikemas kini tanpa panggilan pendaftaran. Jika pendaftaran diperlukan, sistem menjana satu PIN, menyimpannya dengan penyulitan `TokenSeal`, dan menyimpan penanda cubaan sebelum POST ke Meta. PIN tidak dipulangkan dalam API atau audit. Pendaftaran ini tidak mengubah callback, tidak melanggan WABA secara automatik dan tidak menghidupkan AI.

### Respons tidak pasti dan percubaan berulang

Operasi nombor disiri melalui rekod tuntutan tahan lama. Klik berganda tidak menghantar POST kedua. Jika proses terhenti atau respons tidak dapat dipastikan selepas penanda penghantaran disimpan, gunakan **Kemas kini status**. Hasil `CONNECTED` boleh menyelesaikan keadaan tersebut; sistem tidak menghantar semula pendaftaran secara automatik.

Selepas satu cubaan pendaftaran dihantar, termasuk penolakan eksplisit Meta, versi ini mengekalkan penanda cubaan dan memerlukan penyelesaian operasi khusus jika nombor masih belum berdaftar. Jangan memadam baris operasi, menetapkan semula PIN atau memaksa status `registered` untuk mengatasi keadaan ini. Langkah pemulihan tambahan hanya wajar dibina selepas sebab sebenar dan keputusan penyedia diketahui.

Status `messaging_state` hanya datang daripada bukti mesej sedia ada. Semakan atau pendaftaran tidak mencipta bukti penghantaran/penerimaan. UI menyebut `unverified` sebagai **Belum ada bukti mesej**.

## Akses dan rekod

- Semua API senarai, butiran dan tindakan memerlukan pengguna OS aktif yang disenaraikan dalam `platform_admins` dengan status aktif.
- Permintaan perubahan memerlukan CSRF, asal yang sepadan, pengesahan tindakan, serta sasaran eksplisit. GET tidak boleh mengaktifkan nombor atau mengubah akses.
- Penggantungan platform disimpan secara berasingan; tindakan `status` pelanggan tidak boleh membuka semula sekatan platform.
- Audit mencatat admin sebenar dan sasaran. Dashboard tidak mengambil token, PIN, kandungan pengetahuan atau badan perbualan pelanggan.
- Tiada API web untuk memberikan peranan platform. Pemberian akses memerlukan prosedur operator di atas.

## Pengesahan perubahan ini

Fixture hanya dibenarkan pada host `127.0.0.1` dan pangkalan `beshare_platform_admin_test`, menggunakan data sintetik. Ia menolak pangkalan sedia ada yang tidak mempunyai penanda fixture yang betul. Jalankan ujian PHP secara berurutan kerana kedua-duanya menyediakan semula fixture yang sama.

```bash
php os/tests/check-platform-admin.php
php os/tests/check-admin-whatsapp.php
node os/tests/check-admin-ui.mjs
```

Sediakan `BESHARE_ADMIN_TEST_DSN`, `BESHARE_ADMIN_TEST_USER` dan `BESHARE_ADMIN_TEST_PASSWORD` untuk makmal tempatan sahaja. CI khusus menggunakan MySQL 8.4 dan PHP 8.3. Semakan ini meliputi kuasa platform, sasaran pelanggan, CSRF, penggantungan, kawalan AI, operasi pendaftaran, hasil tidak pasti serta UI baharu. Panggilan Meta dalam ujian diganti dengan transport sintetik.

Pratonton pelayar menggunakan enam perniagaan rekaan. Gambar bukan rekod pelanggan production:

- [Desktop 1440px](../../docs/beshare-ai-os/platform-admin-preview/owner-dashboard-1440.png)
- [Telefon 390px](../../docs/beshare-ai-os/platform-admin-preview/owner-dashboard-390.png)
- [Butiran pelanggan](../../docs/beshare-ai-os/platform-admin-preview/owner-customer-1440.png)

## Rujukan teknikal Meta

- [Onboarding business customers as a Tech Provider](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-customers-as-a-tech-provider)
- [Register a business phone number](https://developers.facebook.com/documentation/business-messaging/whatsapp/business-phone-numbers/registration)
- [Phone Number Management API](https://developers.facebook.com/documentation/business-messaging/whatsapp/reference/whatsapp-business-account/phone-number-management-api)
- [Meta WhatsApp Cloud API collection](https://www.postman.com/meta/whatsapp-business-platform/documentation/wlk6lh4/whatsapp-cloud-api)

## Had keluaran

Keluaran ini menyediakan panel operasi dan tindakan pengaktifan yang boleh dikaji melalui PR. Pemasangan schema, pemberian akses sebenar dan cubaan nombor pelanggan adalah langkah deployment berasingan. AI pelanggan umum dan bil/langganan bukan diaktifkan oleh keluaran ini. Kejayaan ujian sintetik tidak menyatakan bahawa nombor pelanggan tertentu sudah diaktifkan pada Meta.
