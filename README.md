# BeShare AI Solution

Tapak gerbang (portal) statik untuk penyelesaian AI BeShare.

## Gerbang

- **Akaun** — daftar dan masuk di `akaun/`. Satu akaun BeShare.
- **Penasihat Perniagaan** — halaman sendiri di `penasihat/` (bukan dalam AI Video atau WhatsApp). Perlukan akaun. Perniagaan disimpan pada akaun itu.
- **WhatsApp AI** — ditutup kepada pelawat _(akan datang)_
- **Meja Grup** — projek sampingan di `meja-grup/`. Draf iklan untuk grup yang nombor sudah sertai. Tidak menyambung ke WhatsApp dan tidak menghantar mesej.
- **IPO** — meja kerja (radar, HATA, portfolio, analisis) — https://beshare-ipo-bot.onrender.com
  Telegram kekal sebagai loceng: amaran harga, notis BELI, dan digest.
- **AI Video Studio** — https://ai-video-saas-ten.vercel.app

## Struktur

- `index.html` — halaman utama (akar repo)
- `penasihat/` — Penasihat Perniagaan, halaman sendiri
- `styles.css` — gaya tapak
- `main.js` — skrip ringkas (tahun footer, kawalan pautan)
- `favicon.svg` — ikon tapak

## Pembangunan

Tapak ini 100% HTML/CSS/JS statik — tiada langkah bina diperlukan. Buka
`index.html` terus dalam pelayar, atau hidangkan folder ini dengan mana-mana
pelayan statik:

```bash
python3 -m http.server 8000
# lawati http://localhost:8000
```
