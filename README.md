# SkyKite Masa Sipariş

Kafeler için Türkçe QR menü, masa siparişi, garson ve işletme yönetim sistemi. Uygulama PHP 8.4, Laravel 13, MySQL, Livewire ve derlenmiş Tailwind CSS kullanır.

## Yerel geliştirme

XAMPP MySQL servisini başlatın. Windows PowerShell'de PHP 8.4'ü XAMPP PHP'sinden önce PATH'e ekleyin:

```powershell
$env:Path = 'C:\xampp\htdocs\tools\php84;' + $env:Path
php -v
```

PHP 8.4 ve Composer kurulu olmalı. Proje kök dizininde bağımlılıkları kurun ve yerel ortam dosyasını oluşturun:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
```

MySQL'de `kafe_qr_ordering` adlı bir `utf8mb4` veritabanı oluşturun. `.env` içindeki `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` ve `DB_PASSWORD` alanlarını yerel MySQL ayarlarınıza göre düzenleyin. Ardından veritabanını hazırlayıp ön yüz varlıklarını derleyin:

```powershell
php artisan migrate
npm install
npm run build
```

İlk süper admin hesabını güvenli parola girişiyle oluşturun; parola terminalde gösterilmez:

```powershell
php artisan app:create-platform-admin
```

Uygulamayı başlatın:

```powershell
php artisan serve
```

Site `http://127.0.0.1:8000` adresinde açılır. Arayüz kaynaklarında değişiklik yaparken ikinci terminalde `npm run dev` çalıştırabilirsiniz.

## Ürün akışları

- **Süper admin:** Kafe, ilk kafe sahibi ve şube oluşturma; kafeyi etkinleştirme veya askıya alma.
- **Kafe sahibi/yöneticisi:** Şube ve ekip yönetimi; ortak veya bağımsız menü; kategori, ürün, fiyat ve bulunurluk; ortak menü kullanılan şubelerde şubeye özel fiyat/bulunurluk; masa ve QR yönetimi.
- **Müşteri:** Masa QR kodundan menüyü görme, sipariş ve not gönderme; kafe ayarına göre hesapsız, isteğe bağlı veya zorunlu kayıt; hesabıyla sadakat bakiyesi görme ve uygun ödülü kullanma.
- **Garson:** Yetkili şubelerde siparişleri sekiz saniyede bir yenileme; sipariş kabul, servis ve gerekçeli iptal; açık adisyona şube menüsünden ürün ekleme; ürün/kişi bazlı kısmi nakit ve fiziksel POS kart tahsilatı; gerekçeli ödeme iptali için geçmiş kaydı; adisyon kapatma.
- **Sadakat:** Ödenip servis edilen hesaplı müşteri siparişlerinde puan ve tamamlanan sipariş biriktirme; puan veya sipariş sayısına bağlı sabit/yüzdeli ödül; iptal edilen sipariş ödülünün telafisi.

Kart ödemeleri uygulamada işlenmez. Garson, fiziksel POS cihazında aldığı ödemeyi sisteme kaydeder. QR siparişi garson onayına kadar beklemede kalır. Sipariş satırları ad ve fiyat anlık görüntüsünü saklar.

## Doğrulama

```powershell
php artisan test
npm run build
```

Özellik testleri süper admin kafe oluşturma, QR sipariş/fiyat anlık görüntüsü, kayıt politikası, şube/tenant yetkisi, şube menü override'ı, kısmi ödeme, ödeme iptali, ödeme öncesi servis ve sadakat akışlarını kapsar.

## Canlıya alma öncesi

Bu depo yerel geliştirme için hazırlanmıştır. Canlı kullanımdan önce HTTPS, güvenli ve gizli `.env`, üretim e-posta servisi, yedekleme/geri yükleme, erişim ve log politikaları, KVKK metinleri ve gerçek kullanıcılarla tarayıcı kabul testi yapılandırılmalıdır. Üretim dağıtımı bu projede yapılmamıştır.
