# Garson tahsilat akışı

Garson, açık adisyondaki ödenebilir ürünleri tek ekrandan arar ve bu tahsilatta ödenecek ürün/adetleri seçer. “Tüm kalan ürünler” ve “Seçimi temizle” kısayolları, ürün araması, adet alanı ve canlı toplam yoğun serviste gereksiz adımları azaltır. Nakit/kart seçimi belirgindir; nakitte müşterinin verdiği tutar girilir, para üstü gösterilir ve eksik tutar kaydı engeller. Kartta nakit alanı gizlenir. Ödeyen kişi isteğe bağlıdır. Kısmi tahsilat seçilen adetleri kapatır; kalan ürünler sonraki arkadaş için açık kalır. Bakiye sıfırlanınca mevcut adisyon kapatma akışı çalışır.

Her fiziksel tahsilat `payments` tablosunda tek bir kayıt olarak saklanır. `payment_allocations` satırları bu tahsilatın hangi sipariş kalemlerine ve adetlere dağıldığını, `cash_received` ve `change_amount` ise nakit verilen ve para üstü tutarlarını saklar. Eski ödemeler mevcut `order_item_id` alanı ve genel ödeme dağıtımı üzerinden okunmaya devam eder. Seçilen ürün ID’leri, adetleri, kalan ürün bakiyeleri ve ödeme tutarı sunucuda doğrulanır; işlem açık masa oturumu kilidi altında kaydedilir. Böylece istemci tutarı değiştirerek fazla tahsilat veya yanlış kalem dağılımı yapamaz. Bir ödeme düzeltildiğinde ilişkili bütün ürün dağılımı tek seferde iptal edilir.

Garson çağrısı aynı açık masa oturumunda en fazla 60 saniyede bir oluşturulur. Süre API tarafında kontrol edilir; sayfa düğmesi de kalan saniyeyi gösterir. Hesap isteme davranışı mevcut uygunluk kurallarıyla sürer.

## Kontrol ölçütleri

- İki farklı fiyatlı ürünü bir ödeme kaydı olarak kaydetmek; kalan ürünlerin bakiyesini korumak.
- Nakit verilen tutardan para üstünü kuruş hassasiyetinde hesaplamak; eksik nakdi reddetmek.
- Kart tahsilatında nakit/para üstü tutarı oluşturmamak.
- Son tahsilatta adisyonu kapatmak; önceki tahsilatı düzeltince dağıtımın tamamını geri açmak.
- Ödenmemiş, ilgisiz bir siparişin iptalini başka siparişe ayrılan tahsilat yüzünden engellememek.
- Aynı masadan 60 saniye içinde tekrarlanan garson çağrısını sunucuda engellemek; süre dolunca tekrarına izin vermek.
- İlgisiz kullanıcı veya başka adisyona ait kalem ID’sini reddetmek.

Değişiklikler PHP sözdizimi kontrolü ve Vite derlemesiyle doğrulanır. Proje Laravel komutları PHP 8.4.1 veya üstünü gerektirir; yerel CLI uyumlu sürüme yükseltildiğinde view cache, migration ve ilgili özellik testleri ayrıca çalıştırılmalıdır.
