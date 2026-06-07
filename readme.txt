=== İç Link Sayıcı ===
Contributors: mayahukuk
Tags: internal links, seo, report, dashboard
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Yazı ve sayfa içeriklerindeki gelen ve çıkan iç linkleri manuel olarak tarar, düşük gelen iç link alan içerikleri üstte raporlar.

== Description ==

İç Link Sayıcı, Maya Hukuk için geliştirilen hafif bir WordPress yönetim eklentisidir. Yönetim başlangıç ekranına "İç Link Sayıcı" kutusu ekler ve sadece yönetici "Kontrol et" düğmesine bastığında tarama yapar.

Eklenti yayınlanmış yazı ve sayfaların editör içeriklerindeki bağlantıları inceler. Her içerik için gelen iç linkler ve çıkan iç linkler ayrı ayrı hesaplanır. Rapor, gelen iç linki en az olan içerikleri en üstte gösterir.

== Features ==

* WordPress başlangıç ekranında hızlı kontrol kutusu.
* Araçlar > İç Link Sayıcı altında tam rapor sayfası.
* Manuel tarama: yönetici basmadıkça çalışmaz.
* Raporu sil düğmesi.
* Tıklanabilir içerik başlığı ve URL.
* Her içerik için gelen iç link sayısı.
* Her içerik için link veren tekil içerik sayısı.
* Her içerik için çıkan iç link sayısı.
* Her içerik için link verilen tekil içerik sayısı.
* Büyük sitelerde yükü azaltmak için parçalı AJAX tarama.
* Rapor verileri autoload edilmeden saklanır.

== Report Columns ==

Rapor şu sütunları gösterir:

* İçerik: Yazı veya sayfa başlığı. Başlık ve URL tıklanabilir.
* Tür: Yazı veya Sayfa.
* Gelen iç link: Site içindeki diğer yazı/sayfa içeriklerinden bu içeriğe verilen toplam link sayısı.
* Link veren içerik: Bu içeriğe en az bir link veren tekil yazı/sayfa sayısı.
* Çıkan iç link: Bu içerikten site içindeki diğer yazı/sayfalara verilen toplam link sayısı.
* Link verilen içerik: Bu içerikten link verilen tekil yazı/sayfa sayısı.
* İşlem: İlgili içeriğin WordPress düzenleme bağlantısı.

Sıralama önceliği gelen iç link sayısıdır. Gelen iç link sayısı eşitse link veren tekil içerik sayısı, ardından çıkan iç link sayısı ve başlık dikkate alınır.

== How Counting Works ==

Eklenti sadece yayınlanmış `post` ve `page` içeriklerini varsayılan olarak tarar. İçerikteki `<a href="...">` bağlantıları okunur ve bağlantı aynı WordPress sitesindeki yayınlanmış bir yazı veya sayfaya denk geliyorsa iç link sayılır.

Aynı kaynak içerikten aynı hedef içeriğe birden fazla link verilirse:

* Gelen iç link toplamına her link eklenir.
* Link veren içerik sayısına aynı kaynak sadece bir kez eklenir.
* Çıkan iç link toplamına her link eklenir.
* Link verilen içerik sayısına aynı hedef sadece bir kez eklenir.

Kendi kendine verilen linkler sayılmaz.

== Performance Notes ==

Bu eklenti site ziyaretçilerine açık sayfalarda tarama yapmaz. Tarama yalnızca WordPress yönetim panelinde, yetkili kullanıcı "Kontrol et" düğmesine bastığında başlar.

Tarama tek seferlik ağır bir işlem yerine küçük parçalar halinde yapılır. Varsayılan paket boyutu 25 içeriktir. Bu sayı geliştiriciler tarafından `maya_ils_scan_batch_size` filtresiyle değiştirilebilir.

Rapor ve geçici tarama verileri WordPress seçeneklerinde autoload kapalı şekilde tutulur. Böylece normal sayfa yüklemelerinde bu veriler otomatik olarak belleğe alınmaz.

== Limitations ==

Bu eklenti editör içeriğindeki bağlantıları sayar. Aşağıdaki bağlantılar rapora dahil değildir:

* Tema şablonlarından gelen menü, header, footer veya sidebar linkleri.
* Kısa kodların çalışma anında ürettiği linkler.
* JavaScript ile sonradan eklenen linkler.
* Harici sitelere verilen linkler.
* `mailto:`, `tel:`, `sms:`, `javascript:`, `data:` ve benzeri bağlantılar.
* Yayında olmayan taslak, özel veya çöp içerikler.

== Developer Notes ==

Varsayılan olarak sadece `post` ve `page` türleri taranır. Özel içerik türlerini dahil etmek için:

`
add_filter( 'maya_ils_post_types', function () {
	return array( 'post', 'page', 'your_custom_post_type' );
} );
`

Paket boyutunu değiştirmek için:

`
add_filter( 'maya_ils_scan_batch_size', function () {
	return 40;
} );
`

Paket boyutunu büyütmek taramayı hızlandırabilir, ancak zayıf hostinglerde yönetim panelinde zaman aşımı riskini artırabilir.

== Installation ==

1. `ic-link-sayici` klasörünü `wp-content/plugins/` içine yükleyin veya ZIP dosyasını WordPress eklenti yükleyicisiyle kurun.
2. WordPress panelinde eklentiyi etkinleştirin.
3. Başlangıç ekranındaki "İç Link Sayıcı" kutusundan "Kontrol et" düğmesine basın.
4. Tam rapor için Araçlar > İç Link Sayıcı sayfasını açın.
5. Eski raporu kaldırmak için "Raporu sil" düğmesini kullanın.

== Frequently Asked Questions ==

= Bu eklenti sitemi yavaşlatır mı? =

Normal ziyaretçi trafiğinde çalışmaz. Sadece yönetici panelinde manuel tarama başlatıldığında işlem yapar. Tarama da küçük paketlere bölünür.

= Menü linkleri neden sayılmıyor? =

Rapor içerik editöründe verilen iç linkleri ölçmek için tasarlanmıştır. Menü, footer ve tema şablon linkleri site genelinde tekrarlandığı için içerik içi linkleme kalitesini ölçerken yanıltıcı olabilir.

= Rapor otomatik güncellenir mi? =

Hayır. Yeni rapor için tekrar "Kontrol et" düğmesine basmanız gerekir.

= Sayılar ne zaman değişir? =

Yazı veya sayfa içeriklerinde iç link eklediğinizde, kaldırdığınızda ya da URL yapısı değiştiğinde yeni tarama sonrası rapor güncellenir.

== Changelog ==

= 1.1.0 =
* Gelen iç link ve çıkan iç link hesapları ayrıldı.
* Link veren tekil içerik ve link verilen tekil içerik sütunları eklendi.
* README detaylandırıldı.

= 1.0.0 =
* İlk sürüm.
