# SkyKite Masa Sipariş

Kafeler için QR menü, masadan sipariş, çalışan paneli ve adisyon yönetimi. Uygulama React/Vite arayüzü, Netlify Functions API'si ve Turso Cloud veritabanıyla çalışır. Canlıya geçiş notları için [`docs/netlify-turso-setup.md`](docs/netlify-turso-setup.md) dosyasına bakın.

## Yerelde çalıştırma

Node.js 24 ve npm kurulu olmalı. Proje klasöründe:

```powershell
npm ci
Copy-Item .env.example .env.local
npm run create:admin
npm run dev
```

Ardından `http://127.0.0.1:5173` adresini açın. `.env.local` varsayılan olarak yerel SQLite dosyasını kullanır. Süper admin oluşturma komutu parolayı terminalde gizli alır. Eski Laravel `.env` dosyası varsa üzerine yazmayın.

## Kontroller

```powershell
npm run typecheck
npm test
npx playwright install chromium
npm run test:e2e
npm run build
```

Netlify'ın derleme ve Functions paketlemesini deploy yapmadan yerelde doğrulamak için:

```powershell
npx --yes netlify-cli@latest build --offline
```

## Canlıya hazırlık

Netlify projesine GitHub deposunu bağlayın. Turso veritabanını oluşturup `TURSO_DATABASE_URL`, `TURSO_AUTH_TOKEN` ve rastgele, sabit bir `QR_TOKEN_ENCRYPTION_KEY` değerini Netlify Functions çalışma ortamına gizli değişken olarak ekleyin. Üretim ayarları Git'e veya `VITE_` değişkenlerine konmamalı. Uygulama ilk API çağrısında şemayı hazırlar; ilk admin `npm run create:admin` komutuyla üretim DB'sine güvenli bağlantı kurularak oluşturulabilir.

Laravel/PHP uygulaması ayrı arşiv olarak `C:\Users\user\Desktop\qr menü` konumunda tutuluyor. Eski MySQL verileri Turso'ya aktarılmadı; canlıya geçmeden önce eski veriyi taşımak veya temiz başlangıç yapmak gerekir.
