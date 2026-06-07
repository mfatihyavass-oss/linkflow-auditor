# İç Link Sayıcı

İç Link Sayıcı, WordPress yönetim panelinde çalışan hafif bir iç link raporlama eklentisidir. Maya Hukuk için geliştirilmiştir.

Eklenti, yönetici `Kontrol et` düğmesine basmadan tarama yapmaz. Yayınlanmış yazı ve sayfa içeriklerindeki bağlantıları inceler, her içerik için gelen iç linkleri ve çıkan iç linkleri ayrı ayrı hesaplar. Rapor, gelen iç linki en az olan içerikleri üstte gösterir.

## Özellikler

- WordPress başlangıç ekranında hızlı kontrol kutusu.
- Araçlar > İç Link Sayıcı altında tam rapor sayfası.
- Manuel tarama; otomatik cron veya ziyaretçi tarafı işlem yok.
- Raporu sil düğmesi.
- Tıklanabilir içerik başlığı ve URL.
- Gelen iç link sayısı.
- Link veren tekil içerik sayısı.
- Çıkan iç link sayısı.
- Link verilen tekil içerik sayısı.
- Büyük sitelerde yükü azaltmak için parçalı AJAX tarama.
- Rapor verileri autoload edilmeden saklanır.

## Rapor Sütunları

| Sütun | Açıklama |
| --- | --- |
| İçerik | Yazı veya sayfa başlığı. Başlık ve URL tıklanabilir. |
| Tür | Yazı veya Sayfa. |
| Gelen iç link | Site içindeki diğer yazı/sayfa içeriklerinden bu içeriğe verilen toplam link sayısı. |
| Link veren içerik | Bu içeriğe en az bir link veren tekil yazı/sayfa sayısı. |
| Çıkan iç link | Bu içerikten site içindeki diğer yazı/sayfalara verilen toplam link sayısı. |
| Link verilen içerik | Bu içerikten link verilen tekil yazı/sayfa sayısı. |
| İşlem | İlgili içeriğin WordPress düzenleme bağlantısı. |

Sıralama önceliği gelen iç link sayısıdır. Gelen iç link sayısı eşitse link veren tekil içerik sayısı, ardından çıkan iç link sayısı ve başlık dikkate alınır.

## Sayım Mantığı

Eklenti varsayılan olarak yayınlanmış `post` ve `page` içeriklerini tarar. İçerikteki `<a href="...">` bağlantıları okunur ve bağlantı aynı WordPress sitesindeki yayınlanmış bir yazı veya sayfaya denk geliyorsa iç link olarak sayılır.

Aynı kaynak içerikten aynı hedef içeriğe birden fazla link verilirse:

- Gelen iç link toplamına her link eklenir.
- Link veren içerik sayısına aynı kaynak sadece bir kez eklenir.
- Çıkan iç link toplamına her link eklenir.
- Link verilen içerik sayısına aynı hedef sadece bir kez eklenir.

Kendi kendine verilen linkler sayılmaz.

## Performans

Bu eklenti normal site ziyaretlerinde çalışmaz. Tarama yalnızca WordPress yönetim panelinde, yetkili kullanıcı `Kontrol et` düğmesine bastığında başlar.

Tarama küçük parçalar halinde yapılır. Varsayılan paket boyutu 25 içeriktir. Bu yaklaşım, özellikle paylaşımlı hostinglerde uzun süren tek bir işlem yerine daha kontrollü bir yönetim paneli işlemi sağlar.

Rapor ve geçici tarama verileri WordPress seçeneklerinde autoload kapalı şekilde tutulur. Böylece normal sayfa yüklemelerinde bu veriler otomatik olarak belleğe alınmaz.

## Sınırlamalar

Bu eklenti editör içeriğindeki bağlantıları sayar. Aşağıdaki bağlantılar rapora dahil değildir:

- Tema şablonlarından gelen menü, header, footer veya sidebar linkleri.
- Kısa kodların çalışma anında ürettiği linkler.
- JavaScript ile sonradan eklenen linkler.
- Harici sitelere verilen linkler.
- `mailto:`, `tel:`, `sms:`, `javascript:`, `data:` ve benzeri bağlantılar.
- Yayında olmayan taslak, özel veya çöp içerikler.

## Kurulum

1. `ic-link-sayici` klasörünü `wp-content/plugins/` içine yükleyin veya ZIP dosyasını WordPress eklenti yükleyicisiyle kurun.
2. WordPress panelinde eklentiyi etkinleştirin.
3. Başlangıç ekranındaki `İç Link Sayıcı` kutusundan `Kontrol et` düğmesine basın.
4. Tam rapor için Araçlar > İç Link Sayıcı sayfasını açın.
5. Eski raporu kaldırmak için `Raporu sil` düğmesini kullanın.

## Geliştirici Notları

Varsayılan olarak sadece `post` ve `page` türleri taranır. Özel içerik türlerini dahil etmek için:

```php
add_filter( 'maya_ils_post_types', function () {
	return array( 'post', 'page', 'your_custom_post_type' );
} );
```

Paket boyutunu değiştirmek için:

```php
add_filter( 'maya_ils_scan_batch_size', function () {
	return 40;
} );
```

Paket boyutunu büyütmek taramayı hızlandırabilir, ancak zayıf hostinglerde yönetim panelinde zaman aşımı riskini artırabilir.

## Sürüm Geçmişi

### 1.1.0

- Gelen iç link ve çıkan iç link hesapları ayrıldı.
- Link veren tekil içerik ve link verilen tekil içerik sütunları eklendi.
- README detaylandırıldı.

### 1.0.0

- İlk sürüm.
