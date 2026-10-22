# Netlify + Turso yerel ve canlı ortam

Bu uygulama React/Vite arayüzü, Netlify Functions API'si ve libSQL/Turso veritabanı kullanır. Laravel sürümü `C:\Users\user\Desktop\qr menü` klasöründe ayrı yedek olarak durur; bu yeni uygulama eski MySQL verisini kendiliğinden içe aktarmaz.

## Yerelde çalıştırma

1. Node.js 24 ve npm kurulu olsun. Proje klasöründe `npm ci` çalıştırın.
2. `.env.example` dosyasını `.env` olarak kopyalayın. Varsayılan `file:` adresi, projede `database/turso/local.db` SQLite dosyasını kullanır.
3. `npm run create:admin` komutunu etkileşimli terminalde çalıştırıp ilk süper admini oluşturun. Şifre terminalde gizli girilir. Zaten bir süper admin varsa komut ikinci bir hesap açmaz.
4. `npm run dev` ile `http://127.0.0.1:5173` adresinde uygulamayı açın. Vite geliştirme sunucusu, aynı API kodunu yerelden çalıştırır.
5. Değişiklik kontrolü için `npm run typecheck`, `npm test` ve `npm run build` komutlarını kullanın.

## Turso veritabanını hazırlama

Turso hesabında yeni bir veritabanı oluşturup uygulamanın kullanacağı URL ile veritabanı auth token'ını alın. `TURSO_DATABASE_URL` ve `TURSO_AUTH_TOKEN` değerlerini Netlify'ın ortam değişkenlerine ekleyin. Uygulama ilk API çağrısında tabloları hazırlar. Turso'nun kendi JavaScript istemci ve kimlik bilgisi akışı [Turso SDK dokümanında](https://docs.turso.tech/sdk) açıklanıyor.

İlk canlı açılıştan önce boş/test veritabanında şema ve temel akışları doğrulayın. İlk süper admini Turso'ya bağlanan güvenli yerel terminalden `npm run create:admin` komutuyla oluşturun; bu komut parola değerini ekrana yazdırmaz ve veritabanında yalnızca bir platform admini olmasına izin verir. Veritabanı auth token'ını sonrasında yalnızca gereken kapsamda tutun ve gizli olarak yönetin.

Eski Laravel MySQL verisi henüz Turso'ya taşınmıyor. Kafeler, kullanıcılar, menüler, siparişler ve masalar yeni uygulamada görünmez; canlı geçişten önce ayrı ve kontrollü bir aktarım/temiz başlangıç kararı gerekir. Eski sistemi yedek olarak saklayın.

## Netlify'a bağlama

Netlify'da GitHub'daki özel `kafe-qr-ordering` deposunu yeni bir siteye bağlayın. Depodaki `netlify.toml` build komutunu `npm run build`, yayın klasörünü `dist`, Functions klasörünü `netlify/functions` olarak tanımlar; `.nvmrc` Node 24'ü seçer. Netlify bu yapılandırmayı deploy sırasında uygular. [Netlify build ayarları](https://docs.netlify.com/build/configure-builds/overview/) ve [Node sürümü seçimi](https://docs.netlify.com/build/configure-builds/manage-dependencies/) dokümanları.

Netlify Project configuration içindeki environment variables bölümünde şu gizli değerleri tanımlayın ve yeni deploy başlatın:

| Değişken | Değer |
| --- | --- |
| `NODE_ENV` | `production` |
| `TURSO_DATABASE_URL` | Turso veritabanının `libsql://...` adresi |
| `TURSO_AUTH_TOKEN` | Turso auth token |
| `QR_TOKEN_ENCRYPTION_KEY` | QR masa belirteçlerini şifrelemek için en az 32 rastgele baytlık sabit anahtar |

Gizli değerleri `netlify.toml`, Git, `VITE_` değişkenleri veya ekran görüntülerine koymayın. Ortam değişkenleri `netlify.toml` içinden Functions çalışma zamanına aktarılmaz; Netlify paneli/CLI/API üzerinden ayarlayın ve değer değişince yeniden deploy edin. [Functions environment variables](https://docs.netlify.com/build/functions/environment-variables/).

Netlify Functions varsayılan olarak Ohio'da çalışır. Turso veritabanını Avrupa'da konumlandırırsanız ve Netlify hesabınız planı destekliyorsa Functions region ayarını Frankfurt (`fra`) yapıp yeniden deploy edin. Netlify bu bölge seçimini Pro ve Enterprise planlarda sunuyor; [Functions region dokümanını](https://docs.netlify.com/build/functions/configuration/#region) hesaptaki planla birlikte kontrol edin. Ürün fotoğrafları Netlify Blobs'ta Frankfurt bölgesine yazılır; [Blobs bölgeleri ve tutarlılık](https://docs.netlify.com/build/data-and-storage/netlify-blobs/).

## Ürün görselleri ve domain

Yerel geliştirmede doğrulanmış JPG, PNG ve WebP görselleri `.local-media` altında tutulur. Netlify üzerinde görseller `skykite-menu-media` Blobs deposuna yazılır; deploy'lar arasında kalıcıdır. Yükleme 4 MB ile sınırlıdır.

Siteyi deploy edip `https://<netlify-site>.netlify.app` üzerinde giriş, menü, QR sipariş, çalışan paneli ve ödeme kaydı akışını kontrol ettikten sonra ayrı domain bağlayın. Domain Keyubu'da kalabilir; Netlify'ın verdiği DNS kayıtlarını domain DNS panelinde tanımlarsınız. Keyubu cPanel'deki PHP hosting bu Netlify Functions/Turso API'sini çalıştırmaz.

## Yayına çıkmadan önce açık işler

- Eski MySQL verisi için aktarım veya kafeleri temiz başlangıçla yeniden oluşturma kararı ve gerekiyorsa migration script'i.
- Gerçek Turso veritabanı/token ve Netlify site ortamı verilmediği için canlı bağlantı ve deploy doğrulaması bu ortamda henüz yapılmadı.
- Netlify'da test deploy, domain/DNS, canlı SMTP ayarları ve yedek/geri yükleme denemesi.
- Üretimde çalıştırmadan önce hesap, masa QR, müşteri siparişi, personel durumu ve kısmi ödeme akışlarının gerçek Turso üzerinde kabul testi.
