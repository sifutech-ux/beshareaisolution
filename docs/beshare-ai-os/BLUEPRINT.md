# BeShare AI OS — Product Blueprint dan Spesifikasi UI/UX

Status dokumen: cadangan untuk kelulusan. Bukan spesifikasi produksi dan bukan kebenaran untuk membina, migrasi, atau deploy.

Tarikh rujukan: 10 Oktober 2026.

Dokumen ini menutup Fasa A sahaja. Pembangunan UI, pangkalan data, Meta, dan pelancaran menunggu kelulusan berasingan.

## 1. Batasan pusingan ini

Yang dibuat sekarang:

- Audit baca-sahaja terhadap repositori sedia ada.
- PRD, peta laman, perjalanan pengguna, sistem reka bentuk, seni bina komponen, matriks fungsi cadangan, peta jalan, skop mock, dan senarai keputusan pemilik.

Yang tidak dibuat:

- Tiada perubahan pada `webhook/index.php`, `whatsapp/meja/`, `admin/`, atau konfigurasi Meta produksi.
- Tiada perubahan pada Foundation 0001. Audit migrasi itu kekal pada cawangan `cursor/foundation-0001-cd0e`.
- Tiada DDL, tiada jadual, tiada sambungan nombor WhatsApp, tiada callback baharu, tiada deploy.
- Sistem BeShare API tidak dipadam dalam pusingan ini. Penyingkiran ialah keputusan pelaksanaan kemudian, selepas OS baharu diluluskan dan laluan ganti wujud.

## 2. Audit repositori

### 2.1 `beshareaisolution`

Tapak gerbang statik HTML/CSS/JS. PHP digunakan untuk meja WhatsApp lama, admin, dan papan. Hosting semasa sesuai untuk fail statik dan PHP. Ia belum diaudit sebagai tempat untuk worker berterusan, queue, atau proses lama.

Permukaan yang wujud dan mesti kekal sehingga arahan pemadaman yang khusus:

| Laluan | Peranan sekarang | Layanan blueprint |
| --- | --- | --- |
| `index.html` | Gerbang lima produk, termasuk WhatsApp AI dan Whatsapp BeShare API | Tidak diubah dalam Fasa A atau Fasa B |
| `akaun/` | Daftar dan masuk akaun portal | Bukan akaun tenant OS baharu |
| `penasihat/` | Penasihat perniagaan | Produk berasingan |
| `whatsapp/` | Aliran tempahan WhatsApp AI lama | Tidak menjadi inbox OS |
| `whatsapp/meja/` | Meja chat, pesanan, token fail | Produksi lama, jangan disentuh |
| `webhook/index.php` | Callback Meta produksi | Jangan diubah atau dijadikan webhook OS |
| `admin/` | Dashboard admin lama | Jangan disentuh |
| `papan/` | Papan BeShare API | Milik model API lama |
| `whatsapp-api/` | Halaman jualan “satu kunci API” | Halaman lama, bukan landing OS |
| `meja-grup/` | Draf iklan grup, tidak menghantar WhatsApp | Produk sampingan |
| `privasi/` | Dasar privasi tapak semasa | OS perlukan teks privasi sendiri selepas model data diluluskan |

`os/` pada cawangan Foundation 0001 ialah migrasi dan runner CLI, dengan `.htaccess` yang menolak HTTP. Laluan itu bukan tempat UI awam.

### 2.2 `whatsapp-ai-sales`

Repositori berasingan. Folder `pintu/` ialah “API BeShare”: pelanggan memegang satu kunci API, BeShare memegang sambungan Cloud API, balasan ikut maklumat kedai, dan manusia boleh mengambil alih. Simpanan tempatan ialah SQLite. Webhook cadangan pintu ialah `/webhook`. Lalai semasa merekod mesej tanpa menghantar ke Graph.

Model ini berbeza daripada OS baharu. OS baharu ialah multi-tenant: setiap pelanggan menyambungkan WABA mereka sendiri melalui Embedded Signup. Tiada kunci API pelanggan, tiada migrasi chat, dan tiada pemindahan token.

Penyingkiran pintu, halaman `whatsapp-api/`, dan papan lama tidak termasuk dalam blueprint ini.

## 3. Definisi produk

BeShare AI OS ialah platform SaaS untuk perniagaan di Malaysia mengendalikan perbualan pelanggan melalui WhatsApp Cloud API rasmi.

Produk ini ialah sistem operasi komunikasi perniagaan, bukan sekadar chatbot. Satu workspace menyatukan sambungan nombor, balasan AI yang berasaskan pengetahuan perniagaan, peti masuk pasukan, pengambilalihan manusia, kenalan, automasi yang patuh kepada peraturan Meta, analitik yang boleh dikira, dan langganan.

Bahasa utama UI ialah Bahasa Melayu. Setiap rentetan UI disimpan sebagai kunci terjemahan supaya Bahasa Inggeris boleh ditambah tanpa menulis semula skrin.

### 3.1 Pengguna

- Pemilik perniagaan: mendaftar, membayar, menyambung nombor, menetapkan AI, dan memindahkan pemilikan melalui aliran khas.
- Admin tenant: mengurus pasukan, pengetahuan, dan tetapan yang dibenarkan matriks kebenaran.
- Staf: melihat dan membalas perbualan yang ditugaskan, tanpa mengkonfigurasi sambungan atau bil.
- Super admin BeShare: mengendalikan tenant, langganan, kesihatan sambungan, dan operasi platform tanpa membaca kandungan perbualan secara lalai.
- Pelanggan akhir perniagaan: hanya bercakap di WhatsApp. Mereka tidak log masuk ke OS.

### 3.2 Janji produk

Selepas sistem diluluskan dan dilancarkan, pelanggan baharu boleh:

1. Mencipta akaun dan mengesahkan e-mel.
2. Merekod profil perniagaan.
3. Memilih Standard atau Ultimate dan melihat status bayaran.
4. Menyambungkan WhatsApp melalui Embedded Signup rasmi.
5. Mengisi pengetahuan dan persona AI.
6. Menguji masuk, keluar, dan tingkah laku AI.
7. Mengaktifkan workspace.
8. Mengurus perbualan bersama pasukan, dengan AI berhenti apabila manusia mengambil alih.

Pelanggan lama sistem fail atau BeShare API tidak dipindahkan. Mereka mendaftar semula.

### 3.3 Bukan janji produk

- Bukan rakan kongsi Meta, bukan Meta Certified, dan bukan Official Meta Partner, melainkan status itu disahkan secara berasingan.
- Penggunaan Cloud API rasmi tidak sama dengan pengiktirafan rakan kongsi.
- Development Mode tidak menerima pelanggan awam. Advanced Access, App Review, dan kelulusan Meta yang berkaitan wajib sebelum pelancaran sebenar.
- Harga pakej tidak termasuk kos mesej Meta, caj payment gateway, atau penggunaan AI, selagi pemilik belum meluluskan sebaliknya.
- UI siap tidak bermaksud fungsi production-ready.

### 3.4 Status fungsi

Setiap modul dilabel salah satu daripada:

- `ui-prototype` — skrin dan mock data berlabel.
- `implemented` — logik aplikasi wujud, belum bersambung dengan Meta atau data sebenar.
- `integrated` — bersambung dengan backend atau Meta dalam persekitaran ujian.
- `tested` — ujian yang ditetapkan lulus.
- `production-ready` — keselamatan, tenancy, pemulihan, dan kelulusan pelancaran lengkap.

Blueprint ini sendiri belum memasuki mana-mana status pelaksanaan.

## 4. Keperluan produk

### 4.1 Akaun dan tenant

- Satu pengguna boleh menjadi ahli lebih daripada satu perniagaan pada masa depan. Fasa pertama UI mengandaikan satu workspace aktif setiap sesi, dengan penukar workspace hanya jika data keahlian wujud.
- `business_id` datang daripada sesi keahlian, bukan daripada badan permintaan atau parameter URL.
- Kata laluan disimpan sebagai hash. Token WhatsApp disulitkan apabila backend diluluskan. Rahsia tidak masuk ke repositori.
- Sesi mengesahkan e-mel sebelum onboarding perniagaan diteruskan.
- Pengguna menerima Terma dan Dasar Privasi pada pendaftaran. Teks undang-undang OS belum ditulis.

### 4.2 Onboarding

Enam langkah, boleh disambung semula:

1. Akaun: nama, e-mel, kata laluan, pengesahan e-mel, terma dan privasi.
2. Profil perniagaan: nama, hubungan, industri, maklumat operasi asas.
3. Langganan: Standard atau Ultimate, ringkasan yuran tetap dan yuran bulanan, status bayaran.
4. WhatsApp: Embedded Signup, Facebook Login, WABA dan nombor mengikut aliran Meta, pertukaran token di pelayan, langganan webhook, pengesahan pendaftaran.
5. AI: persona, penerangan perniagaan, pengetahuan, waktu operasi, peraturan serahan manusia.
6. Ujian dan aktifkan: mesej keluar ujian, mesej masuk ujian, empat keadaan sambungan, semakan tingkah laku AI, pengaktifan workspace.

Nombor tidak dipaparkan sebagai bersambung penuh hanya kerana token disimpan. Empat keadaan kekal berasingan: `token_state`, `webhook_state`, `registration_state`, `messaging_state`.

Embedded Signup kekal pada kontrak projek sedia ada: dua saluran tanpa state OAuth yang digema, `correlation_hash` diikat pada sesi sebelum login, kod ditukar di pelayan, dan cipher tidak menjadi sambungan sehingga Graph mengesahkan `waba_id` dan `phone_number_id`. Integrasi sebenar tidak dihidupkan dalam fasa UI.

### 4.3 Peti masuk dan pengambilalihan

Desktop menggunakan tiga panel. Mudah alih membuka senarai, perbualan, dan profil sebagai paparan berasingan.

Mod perbualan:

- `ai_active` — AI boleh membalas.
- `human_takeover` — staf memegang kawalan, AI tidak menghantar balasan automatik.
- `ai_paused` — AI dihentikan tanpa menutup perbualan.
- `resolved` — perbualan ditutup. Pembukaan semula menetapkan mod secara eksplisit, bukan secara senyap.

Satu perbualan tidak boleh menerima balasan automatik AI dan balasan staf pada masa yang sama. Status penghantaran hanya dipadankan dengan id mesej Graph. Tetingkap perkhidmatan pelanggan WhatsApp dan templat mesej dihormati. Automasi tidak menghantar di luar peraturan itu.

### 4.4 AI dan pengetahuan

Setiap tenant mempunyai arahan, suara jenama, bahasa, waktu operasi, dan sumber pengetahuan sendiri. AI tidak mereka harga, stok, polisi, atau promosi apabila sumber tidak ada. Jawapan tidak pasti meminta penjelasan atau menyerahkan kepada manusia.

Lapisan pembekal AI memisahkan aplikasi daripada vendor model. Pilihan vendor belum diluluskan. Sistem lama tidak dianggap sebagai OpenAI.

Pengetahuan disusun untuk pengguna bukan teknikal: profil, produk, FAQ, harga, promosi, penghantaran, bayaran, bayaran balik, waktu operasi, dan arahan khas. Dokumen pelanggan tidak mengatasi arahan keselamatan sistem.

Playground menunjukkan jawapan, sumber yang digunakan, dan sama ada maklumat tidak mencukupi.

### 4.5 Automasi

Fungsi asas yang direka dahulu, sebelum pembina visual penuh:

- Sambutan.
- Balasan dalam waktu operasi.
- Balasan luar waktu.
- Laluan kata kunci.
- Serahan manusia.
- Susulan.
- Pemberitahuan dalaman.
- Tugasan perbualan.

Enjin mesti menolak gelung, pelaksanaan pendua, dan penghantaran berulang. UI awal ialah senarai peraturan dengan pratonton. Kanvas visual ialah arah perkembangan, bukan skop fasa mock pertama.

### 4.6 Pasukan

Peranan tetap: Owner, Admin, Staff. Kebenaran disahkan di backend apabila backend wujud. UI hanya menyembunyikan tindakan yang sesi semasa tidak boleh buat. Matriks di bawah ialah cadangan.

Pemindahan pemilik bukan tetapan biasa. Ia mengikuti mesin yang sudah direka: buka `provisioning` tanpa menukar pemilik, ubah keahlian, tetapkan `owner_user_id` semasa masih `provisioning`, kemudian kembali ke `active` atau `suspended`. Setiap langkah ialah tindakan berasingan oleh pemilik semasa.

### 4.7 Analitik

Hanya metrik yang boleh dikira daripada peristiwa yang disimpan:

- Perbualan masuk.
- Perbualan yang dibalas AI.
- Perbualan yang dipegang manusia.
- Masa tindak balas purata, dengan takrif masa mula dan masa balas pertama.
- Perbualan belum dibalas.
- Hasil penghantaran yang datang daripada status Graph.
- Kadar serahan AI, iaitu bahagian perbualan yang bertukar kepada manusia.

Kadar konversi, atribusi hasil, dan ketepatan AI tidak dipaparkan sehingga definisi pengukuran diluluskan.

### 4.8 Super admin

Direktori tenant, status daftar, status langganan, kesihatan sambungan, kegagalan onboarding, amaran, penggunaan agregat, log audit, bendera ciri, dan konfigurasi platform.

Super admin tidak melihat isi perbualan secara lalai. Akses sokongan kepada data tenant memerlukan tujuan, kebenaran, dan rekod audit. Butiran aliran kebenaran sokongan masih menunggu kelulusan pemilik.

### 4.9 Bil

Pakej yang dipersetujui untuk dipaparkan:

| Pakej | Yuran pemasangan | Langganan bulanan |
| --- | --- | --- |
| Standard | RM1,200 | RM800 |
| Ultimate | RM1,800 | RM1,000 |

Sistem perlu mampu menyimpan yuran sekali, langganan berulang, invois, status, tarikh pembaharuan, kegagalan bayaran, tempoh ihsan, penggantungan, dan pengaktifan semula. Had fungsi antara dua pakej belum diputuskan. Kos Meta dan caj gateway dipaparkan sebagai kos berasingan.

### 4.10 Keselamatan

Pengasingan tenant, RBAC di pelayan, hash kata laluan, CSRF, sesi yang dikukuhkan, had kadar, pengesahan input, pelarian output, penyulitan token, rahsia di luar `public_html`, log audit, pengesahan tandatangan webhook, pemprosesan idempoten, perlindungan ulang tayang yang berkenaan, retensi dan pemadaman, sandaran, dan pemantauan ralat.

Log tidak menyimpan token, App Secret, kod kebenaran, atau kelayakan. Tiada endpoint migrasi awam.

## 5. Peta laman

Laluan produk baharu dicadangkan di `ai-os/` supaya tidak bercampur dengan `os/` yang menolak HTTP dan tidak bercampur dengan `/webhook/` produksi. Nama laluan ini masih cadangan.

### 5.1 Awam

- `ai-os/` — landing.
- `ai-os/harga/` — pakej dan kos yang tidak termasuk.
- `ai-os/cara-kerja/` — aliran daftar hingga aktif, boleh menjadi bahagian landing jika pemilik mahu satu halaman.
- `ai-os/faq/`
- `ai-os/terma/`
- `ai-os/privasi/`
- `ai-os/daftar/`
- `ai-os/masuk/`
- `ai-os/lupa-kata-laluan/`
- `ai-os/sahkan-emel/`

### 5.2 Onboarding

- `ai-os/mula/` — penyambung semula, menunjukkan langkah semasa.
- `ai-os/mula/akaun/`
- `ai-os/mula/perniagaan/`
- `ai-os/mula/langganan/`
- `ai-os/mula/whatsapp/`
- `ai-os/mula/ai/`
- `ai-os/mula/ujian/`

### 5.3 Workspace tenant

- `ai-os/app/` — dashboard.
- `ai-os/app/peti/` — senarai perbualan.
- `ai-os/app/peti/{id}/` — perbualan. Pada mudah alih, profil ialah laluan atau laci berasingan.
- `ai-os/app/kenalan/`
- `ai-os/app/kenalan/{id}/`
- `ai-os/app/pengetahuan/`
- `ai-os/app/pengetahuan/ujian/`
- `ai-os/app/automasi/`
- `ai-os/app/automasi/{id}/`
- `ai-os/app/analitik/`
- `ai-os/app/pasukan/`
- `ai-os/app/sambungan/`
- `ai-os/app/bil/`
- `ai-os/app/tetapan/`
- `ai-os/app/audit/` — untuk Owner dan Admin, jika diluluskan.

### 5.4 Super admin

- `ai-os/pengendali/`
- `ai-os/pengendali/tenant/`
- `ai-os/pengendali/tenant/{id}/` — metadata dan kesihatan, bukan transkrip.
- `ai-os/pengendali/onboarding/`
- `ai-os/pengendali/langganan/`
- `ai-os/pengendali/amaran/`
- `ai-os/pengendali/audit/`
- `ai-os/pengendali/bendera/`

### 5.5 Webhook baharu

Laluan cadangan, tidak didaftarkan pada Meta dalam pusingan ini: `ai-os/webhook/masuk/`. Ia berasingan daripada `webhook/index.php`.

## 6. Perjalanan pengguna

### 6.1 Pelanggan baharu hingga workspace aktif

1. Landing menerangkan Cloud API rasmi, cara kerja, harga, dan kos yang tidak termasuk.
2. Daftar akaun dan sahkan e-mel.
3. Isi profil perniagaan. Keluar dari laman boleh kembali ke langkah yang sama.
4. Pilih pakej. UI mock menunjukkan status bayaran sebagai demo, berlabel, sehingga gateway diluluskan.
5. Skrin WhatsApp menerangkan empat keadaan sambungan. Butang Embedded Signup pada prototype tidak memanggil Meta.
6. Isi pengetahuan minimum dan peraturan serahan.
7. Skrin ujian menyenaraikan semakan keluar, masuk, dan tingkah laku AI sebagai item belum selesai, bukan sebagai kejayaan palsu.
8. Workspace dibuka dengan banner demo selagi data bukan dari backend.

### 6.2 Staf membalas

1. Staf masuk dan melihat peti dengan penapis tugasan sendiri.
2. Membuka perbualan. Penunjuk mod menunjukkan AI aktif atau manusia.
3. Tindakan “Ambil alih” menukar mod kepada `human_takeover` dan menyembunyikan jangkaan balasan AI.
4. Balasan staf kekal dalam komposer manusia.
5. “Pulangkan kepada AI” meminta pengesahan, kemudian kembali ke `ai_active`.
6. “Selesai” menutup perbualan.

Pada prototype, semua peralihan ini berlaku dalam keadaan mock pada pelayar dan dipaparkan sebagai demo.

### 6.3 Pemilik menguji AI

1. Buka pengetahuan dan pilih seksyen.
2. Simpan draf. Prototype menyimpan pada keadaan tempatan berlabel, bukan pangkalan data.
3. Buka playground, hantar mesej contoh.
4. Lihat jawapan, sumber, atau tanda maklumat tidak mencukupi.
5. Uji serahan manusia dan lihat mod perbualan contoh bertukar.

Jawapan playground prototype ialah jawapan tetap yang menunjukkan bentuk UI, bukan model sebenar.

### 6.4 Pemilik melihat bil

1. Halaman bil menunjukkan pakej, yuran pemasangan, yuran bulanan, status, tarikh pembaharuan, dan invois.
2. Kos mesej Meta dan caj gateway dipaparkan sebagai baris berasingan “tidak termasuk”.
3. Kegagalan bayaran, tempoh ihsan, dan penggantungan mempunyai keadaan UI walaupun pembayaran sebenar belum disambungkan.

### 6.5 Super admin menyiasat onboarding

1. Masuk ke workspace pengendali.
2. Lihat tenant yang tersekat, langkah onboarding, dan kod keadaan sambungan.
3. Tidak ada panel transkrip.
4. Tindakan sokongan yang membaca data tenant tidak ada pada prototype. Ia menunggu reka bentuk kebenaran.

### 6.6 Pemindahan pemilik

Tidak dibina sebagai borang suntingan. Perjalanan yang akan direka kemudian:

1. Pemilik semasa mengesahkan identiti.
2. Status perniagaan menjadi `provisioning` tanpa menukar `owner_user_id`.
3. Keahlian pemilik baharu ditetapkan.
4. `owner_user_id` dikemas kini semasa status masih `provisioning`.
5. Status kembali ke `active` atau `suspended`.

UI Fasa B hanya menyimpan tempat dalam tetapan: “Pemindahan pemilikan belum dibuka”, tanpa tindakan yang mengubah pemilik.

## 7. Sistem reka bentuk

Rujukan visual ialah arah, bukan mockup akhir dan bukan aset untuk disalin. Dashboard rujukan menunjukkan SaaS gelap dengan aksen ungu. Peti masuk rujukan menunjukkan tiga panel yang terang. Studio rujukan menunjukkan kanvas aliran sebagai arah kemudian. Jangan salin tanda, nama, atau susun atur produk pihak ketiga.

### 7.1 Arah

Moden, enterprise, senyap, dan jelas. Tipografi dan jarak lebih penting daripada ilustrasi. Kad bulat dengan bayang nipis. Kaca hanya pada lapisan kecil seperti menu, bukan pada seluruh halaman. Gerakan pendek dan ringan. Kontras mencukupi untuk teks dan status. Desktop, tablet, dan mudah alih. Mod cerah dan gelap.

Elakkan graf sesak, animasi berterusan, dan hiasan yang menghalang kerja.

### 7.2 Token

Token disimpan sebagai pembolehubah CSS. Komponen tidak menggunakan warna mentah.

Warna cadangan, belum dikunci sebagai jenama muktamad:

- Dakwat: `#12141c` untuk teks utama mod cerah dan permukaan utama mod gelap.
- Kanvas cerah: `#f6f7fb`.
- Kanvas gelap: `#0e1118`.
- Permukaan cerah: `#ffffff`. Permukaan gelap: `#171b26`.
- Aksen: `#6d5efc`, dengan hover `#5b4de6`.
- Aksen sokongan: `#8b7cff`, digunakan jarang.
- Berjaya: `#0f7b4c`. Amaran: `#9a6700`. Ralat: `#b42318`. Maklumat: `#175cd3`.
- Sempadan: hitam pada 8% dalam mod cerah, putih pada 10% dalam mod gelap.

Jarak: 4, 8, 12, 16, 24, 32, 48. Radius: 8 untuk input, 12 untuk kad, 16 untuk modal. Bayang: satu aras rehat dan satu aras timbul. Tipografi: satu keluarga sans yang sudah digunakan tapak jika lesennya sesuai, saiz 12, 14, 16, 20, 28, 40. Berat 400, 560, 680. Baris 1.45 untuk teks dan 1.2 untuk tajuk.

Fokus papan kekunci kelihatan. Sasaran sentuh minimum 44px pada mudah alih. Status tidak bergantung pada warna sahaja. Ikon disertakan teks.

### 7.3 Komponen

Setiap komponen mempunyai keadaan rehat, hover, fokus, aktif, dilumpuhkan, ralat, dan memuat jika ia tindakan.

- Cangkerang aplikasi: sidebar, bar atas, kawasan kandungan, laci mudah alih.
- Bar atas: workspace, carian, suis tema, menu pengguna.
- Kad dan KPI: nilai, label, nota sumber data, keadaan kosong.
- Jadual: kepala, isih, kosong, ralat, rangka.
- Carian dan penapis.
- Tab dan kawalan bersegmen.
- Modal pengesahan.
- Toast.
- Keadaan kosong, rangka, dan ralat.
- Borang: label, bantuan, ralat medan, ringkasan ralat.
- Langkah onboarding.
- Laci.
- Butang dan input yang boleh dicapai papan kekunci.
- Lencana status sambungan, empat dimensi berasingan.
- Gelembung mesej, resit penghantaran, dan penunjuk mod AI atau manusia.
- Banner demo yang tidak boleh ditutup pada prototype.

### 7.4 Seni bina UI Fasa B

Fasa B dibina sebagai HTML, CSS, dan JavaScript statik, selaras dengan hosting semasa dan tanpa proses bina wajib. Token dan komponen dikongsi melalui fail CSS dan skrip kecil. Tiada framework baharu diperkenalkan sebelum pemilik meluluskannya.

Data mock tinggal dalam satu modul skrip, dipisahkan daripada sebarang panggilan rangkaian. Setiap halaman mock memaparkan banner “Data demo”. Tiada nombor, nama pelanggan sebenar, atau token.

Apabila backend diluluskan, halaman yang sama bertukar sumber data. Kontrak data mock ditulis supaya bentuknya menyerupai kontrak API yang dirancang, tetapi label demo kekal sehingga integrasi diuji.

## 8. Spesifikasi skrin

Setiap skrin di bawah mempunyai tujuan, aliran, dan keadaan. Pada Fasa B semuanya `ui-prototype`.

### 8.1 Landing

Tujuan: menerangkan OS dan membawa pendaftaran tanpa dakwaan rakan kongsi Meta.

Aliran: hero, demo visual berlabel rujukan, auto-balas, perbezaan Cloud API rasmi dengan status rakan kongsi, cara kerja enam langkah, fungsi utama, dua pakej, FAQ, seruan daftar, footer undang-undang.

Keadaan: muat biasa, pautan harga, pautan daftar. Tiada metrik pelanggan palsu.

### 8.2 Daftar, masuk, sahkan e-mel, lupa kata laluan

Tujuan: akaun pengguna OS, berasingan daripada `akaun/` portal lama.

Aliran daftar: nama, e-mel, kata laluan, pengesahan kata laluan, kotak terma dan privasi, hantar, skrin semak peti e-mel. Masuk menolak akaun yang belum disahkan dengan tindakan hantar semula. Lupa kata laluan mengesahkan penghantaran tanpa mendedahkan sama ada e-mel wujud.

Keadaan: medan kosong, format tidak sah, kata laluan pendek, terma belum diterima, e-mel sudah digunakan, rangka semasa hantar, ralat rangkaian, berjaya.

### 8.3 Onboarding

Tujuan: enam langkah yang boleh ditinggalkan dan disambung.

Aliran: penunjuk langkah, satu tugas setiap skrin, kembali dan teruskan. Langkah WhatsApp menunjukkan empat keadaan sebagai belum bermula. Langkah ujian tidak menandakan lulus sendiri.

Keadaan: draf tersimpan, langkah dikunci kerana langkah sebelumnya belum lengkap, ralat pengesahan, keluar dan sambung semula, demo untuk bayaran dan Meta.

### 8.4 Dashboard

Tujuan: status workspace hari ini.

Paparan: empat keadaan WhatsApp, status automasi AI, perbualan masuk, menunggu manusia, belum dibaca, diurus AI, hasil penghantaran, status langganan, aktiviti terkini.

Keadaan: demo berlabel, kosong untuk tenant baharu, sambungan separa, langganan tergendala, ralat muat, rangka.

Tiada graf hiasan yang menggunakan nombor seolah-olah produksi.

### 8.5 Peti masuk

Tujuan: kerja harian pasukan.

Desktop: senarai, perbualan, profil. Senarai ada carian dan penapis belum dibaca, tugasan saya, AI, manusia, ditutup. Tengah ada sejarah, komposer, nota, dan mod. Kanan ada profil, tag, ejen, status, butiran, garis masa, dan tindakan pantas.

Mudah alih: senarai dahulu, perbualan seterusnya, profil dalam laci.

Keadaan: kosong, tiada hasil carian, memuat sejarah, gagal menghantar, tetingkap perkhidmatan tamat dengan penjelasan templat, mod AI, mod manusia, dijeda, selesai, konflik dua pelakon yang disekat oleh UI.

### 8.6 Pengetahuan dan playground

Tujuan: pemilik bukan teknikal mengisi fakta dan menguji jawapan.

Aliran: senarai seksyen, editor, hidupkan atau matikan entri, buka playground. Playground menunjukkan input, jawapan demo, sumber, tanda tidak cukup maklumat, dan serahan.

Keadaan: seksyen kosong, entri dilumpuhkan, pengesahan medan, pratonton, ralat simpan demo.

### 8.7 Kenalan

Tujuan: direktori tenant sendiri.

Aliran: carian, penapis tag, profil, sejarah, nota, staf ditugaskan, keutamaan komunikasi. Import dan eksport kelihatan tetapi dilumpuhkan sehingga kebenaran dan backend diluluskan.

Keadaan: kosong, tiada hasil, profil tanpa perbualan, ralat.

### 8.8 Automasi

Tujuan: peraturan asas, bukan kanvas penuh.

Aliran: senarai peraturan, hidupkan atau matikan, edit syarat dan tindakan, amaran gelung dan pendua. Pautan “Pembina visual kemudian” menerangkan bahawa kanvas belum dibina.

Keadaan: tiada peraturan, peraturan bertindih, tindakan WhatsApp yang tidak sah disekat dengan penjelasan, demo.

### 8.9 Analitik

Tujuan: nombor yang boleh dikira, dengan takrif di sebelah setiap metrik.

Keadaan: tempoh kosong, data demo berlabel, metrik yang belum ditakrif disembunyikan.

### 8.10 Pasukan, sambungan, bil, tetapan

Pasukan: jemputan, peranan, buang akses. Jemputan prototype tidak menghantar e-mel.

Sambungan: empat keadaan, ralat terakhir, tindakan sambung yang tidak memanggil Meta.

Bil: dua pakej, status, invois demo, kos tidak termasuk, kegagalan, ihsan, gantung, aktif semula.

Tetapan: profil perniagaan, bahasa, tema, dan tempat pemindahan pemilik yang terkunci.

### 8.11 Super admin

Tujuan: operasi platform tanpa transkrip.

Keadaan: tiada tenant, tenant tersekat, langganan tertunggak, sambungan tidak sihat, log audit kosong.

## 9. Matriks fungsi cadangan

Harga Standard dan Ultimate sudah dipersetujui. Perbezaan fungsi belum. Cadangan lalai: kedua-dua pakej menerima fungsi produk yang sama sehingga pemilik meluluskan had. Jadual ini bukan keputusan.

| Fungsi | Standard | Ultimate | Kelulusan |
| --- | --- | --- | --- |
| Yuran pemasangan | RM1,200 | RM1,800 | Sudah dipersetujui untuk dipaparkan |
| Yuran bulanan | RM800 | RM1,000 | Sudah dipersetujui untuk dipaparkan |
| Cloud API rasmi dan Embedded Signup | Ya | Ya | Perlu disahkan sebagai sama |
| Peti masuk dan pengambilalihan manusia | Ya | Ya | Perlu disahkan sebagai sama |
| Pengetahuan dan playground | Ya | Ya | Perlu disahkan sebagai sama |
| Automasi asas | Ya | Ya | Perlu disahkan sebagai sama |
| Analitik yang boleh dikira | Ya | Ya | Perlu disahkan sebagai sama |
| Bilangan staf | Belum dicadangkan sebagai had | Belum dicadangkan sebagai had | Perlu keputusan |
| Kuota panggilan AI | Belum ditetapkan | Belum ditetapkan | Perlu keputusan |
| Bilangan automasi | Belum ditetapkan | Belum ditetapkan | Perlu keputusan |
| Import kenalan | Belum ditetapkan | Belum ditetapkan | Perlu keputusan |
| Pembina visual | Bukan skop sekarang | Bukan skop sekarang | Perlu keputusan jika dijadikan pembeza |
| Kos mesej Meta | Tidak termasuk | Tidak termasuk | Perlu disahkan pada halaman harga |
| Caj payment gateway | Tidak termasuk | Tidak termasuk | Perlu disahkan pada halaman harga |

Jika pemilik mahu pembeza, keputusan yang diperlukan ialah nombor staf, kuota AI, bilangan peraturan, dan sama ada pembina visual hanya untuk Ultimate. Jangan menguatkuasakan nombor sebelum itu.

### 9.1 Kebenaran cadangan

| Tindakan | Owner | Admin | Staff |
| --- | --- | --- | --- |
| Lihat perbualan workspace | Ya | Ya | Hanya yang ditugaskan, cadangan |
| Balas pelanggan | Ya | Ya | Ya, jika ditugaskan |
| Ambil alih dan pulangkan AI | Ya | Ya | Ya, untuk perbualan sendiri |
| Konfigurasi AI dan pengetahuan | Ya | Ya | Tidak |
| Urus sambungan WhatsApp | Ya | Tidak, cadangan | Tidak |
| Urus pasukan | Ya | Ya | Tidak |
| Lihat dan urus bil | Ya | Tidak, cadangan | Tidak |
| Eksport data | Ya | Ya, dengan audit | Tidak |
| Ubah tetapan perniagaan | Ya | Ya, kecuali pemilikan | Tidak |
| Pindah pemilik | Ya, aliran khas | Tidak | Tidak |

Cadangan “staf hanya melihat perbualan ditugaskan” dan “admin tidak mengurus sambungan atau bil” perlu disahkan. Sehingga itu, UI prototype menggunakan matriks ini sebagai demo dan menandakannya sebagai cadangan.

## 10. Seni bina teknikal yang dirancang

Foundation 0001 kekal asas pangkalan data selepas auditnya diluluskan dan dilaksanakan secara berasingan. Skema itu tidak diubah dalam blueprint ini.

Jadual yang sudah dalam skop 0001, pada cawangan audit: `schema_migrations`, `users`, `businesses`, `business_users`, `whatsapp_connections`, `onboarding_sessions`, `webhook_events`, `audit_logs`.

Migrasi kemudian, masing-masing versi baharu dan semakan sendiri:

- 0002 peti masuk: kenalan, perbualan, mesej, status mesej, mesej keluar, dengan kunci idempoten pada id Graph.
- 0003 AI: konfigurasi, sumber pengetahuan, tanpa foreign key songsang ke perniagaan.
- 0004 CRM: tag, nota, tugasan.
- 0005 automasi: peraturan dan jejak pelaksanaan.
- 0006 bil: pakej, langganan, invois, peristiwa bayaran.
- 0007 penggunaan dan pemberitahuan.

Frontend Fasa B tidak memanggil pangkalan data. Backend kemudian ialah PHP 8.3 dan MariaDB yang sudah diketahui pada hosting, dengan API di bawah laluan baharu. Queue dan worker tidak diandaikan tersedia pada shared hosting. Kerja latar yang diperlukan, seperti ulang cuba webhook, mesti direka supaya muat pada cron atau mekanisme yang diaudit sebelum dilaksanakan.

Sempadan:

- UI memaparkan dan mengumpul input.
- API mengesahkan sesi, tenant, dan kebenaran.
- Pemproses peristiwa mengesahkan tandatangan webhook dan menyimpan peristiwa sekali.
- Integrasi Meta menukar kod, menghantar mesej, dan membaca status.
- Enjin AI membaca pengetahuan tenant melalui lapisan pembekal.

## 11. Peta jalan mengikut kebergantungan

Fasa A, blueprint ini, tidak membuka kunci pelaksanaan dengan sendirinya.

1. Fasa B, UI prototype dengan mock. Bergantung pada kelulusan blueprint, token visual, dan matriks yang ditandakan sebagai cadangan. Tidak bergantung pada pangkalan data atau Meta.
2. Kelulusan dan pelaksanaan Foundation 0001. Bergantung pada penutupan audit migrasi. Menyekat akaun sebenar, tenant, dan webhook.
3. Fasa C, backend. Bergantung pada 0001 dan kontrak API yang ditulis daripada data mock.
4. Migrasi 0002 hingga 0005. Bergantung pada Fasa C dan semakan setiap versi.
5. Fasa D, Meta. Bergantung pada sambungan, ujian Embedded Signup, App Review, dan webhook baharu. Menyekat pengaktifan nombor sebenar.
6. Fasa E, AI. Bergantung pada pilihan pembekal, privasi, dan 0003.
7. Fasa F, bil. Bergantung pada gateway, tempoh ihsan, dan sama ada had pakej diluluskan.
8. Fasa G, ujian dan pelancaran. Bergantung pada semua di atas, termasuk pengasingan tenant dan rancangan deploy.

Pemadaman BeShare API, pintu, dan halaman lama berlaku selepas OS pengganti production-ready dan selepas arahan pemadaman yang khusus. Ia bukan prasyarat Fasa B.

## 12. Modul yang boleh dibina dengan mock

Boleh dimulakan tanpa pangkalan data dan tanpa Meta, selepas blueprint diluluskan:

- Landing, harga, FAQ, dan footer.
- Skrin akaun dalam keadaan UI sahaja.
- Onboarding enam langkah dengan draf tempatan berlabel.
- Dashboard, peti masuk, kenalan, pengetahuan, playground skrip, automasi senarai, analitik, pasukan, sambungan, bil, tetapan, dan super admin.
- Token, komponen, mod cerah dan gelap, dan susun atur responsif.
- Banner demo dan keadaan kosong, ralat, rangka, dan pengesahan borang.

Tidak boleh dimulakan atas nama mock:

- Panggil Graph, Embedded Signup sebenar, atau webhook Meta.
- Simpan token atau kata laluan sebenar.
- Laksanakan DDL atau menulis ke `u879723783_beshare_os`.
- Mengubah callback produksi.
- Menandakan bayaran, sambungan, atau balasan AI sebagai berjaya.
- Memadam sistem lama.

## 13. Keputusan yang menunggu pemilik

1. Luluskan blueprint ini sebagai asas Fasa B, atau tandakan bahagian yang perlu diubah.
2. Sahkan laluan awam `ai-os/` dan webhook baharu `ai-os/webhook/masuk/`.
3. Kunci atau ubah palet dan tipografi cadangan.
4. Sahkan UI statik tanpa framework untuk Fasa B.
5. Sahkan kedua-dua pakej mempunyai fungsi yang sama sehingga had diluluskan, atau tetapkan had staf, kuota AI, dan automasi.
6. Sahkan kos mesej Meta dan caj gateway dipaparkan sebagai tidak termasuk.
7. Sahkan matriks kebenaran, terutamanya skop staf dan sama ada admin mengurus sambungan serta bil.
8. Pilih pembekal AI dan nyatakan sama ada kandungan perbualan meninggalkan hosting. Dasar privasi OS bergantung pada jawapan ini.
9. Luluskan tempoh ihsan, tingkah laku penggantungan, dan gateway bayaran.
10. Tentukan sama ada Fasa B boleh bermula sebelum Foundation 0001 dilaksanakan. Cadangan blueprint: ya, kerana mock tidak menyentuh pangkalan data.
11. Tentukan bila sistem BeShare API, `pintu/`, `whatsapp-api/`, dan `papan/` akan dipadam. Bukan dalam fasa ini.
12. Luluskan teks terma dan privasi sebelum daftar sebenar dibuka.
13. Luluskan sama ada super admin boleh meminta akses sokongan terhad kepada transkrip, dan bagaimana kebenaran itu direkod.

## 14. Kriteria penerimaan blueprint

Blueprint diterima untuk membuka Fasa B apabila pemilik mengesahkan bahagian 13 yang berkaitan dengan UI, atau menulis pembetulan. Penerimaan blueprint tidak meluluskan DDL, Meta, bayaran, pemadaman sistem lama, atau pelancaran.
