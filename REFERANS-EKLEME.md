# Yeni referans ekleme (WordPress paneli)

Bütün referanslar ve şirket haberleri WordPress panelinden yönetilir; **Yayınla**'ya basınca sitede görünür. Eklentiyi
yeniden derlemek ya da yüklemek gerekmez. Panelin dili İngilizce olduğu için aşağıda düğme ve kutu adları İngilizce yazıldı.

## Bir kerelik: mevcut 42 referansı panele aktarma

Yeni eklentiyi yükledikten sonra **Settings → Unixpadel Static** sayfasında, *Built references → WP admin* bölümündeki
**Import built references** düğmesine bir kez basın.
- Sitedeki 42 referans (37 proje ve 5 şirket haberi) 6 dildeki metinleri, fotoğrafları, ülke ve kort tipleriyle birlikte
  **References** listesine eklenir. Bundan sonra bunlar da paneldeki diğer referanslar gibi düzenlenir.
- Kök dizindeki 8 referans (ör. `/cesme-panoramic-padel-courts/`) `/references/…/` altına taşınır; eski adres yenisine
  301 ile yönlenir.
- Medya Kütüphanesi'nde bulunamayan fotoğraf olursa referans o fotoğrafı adresinden göstermeye devam eder. Düzenleme
  ekranında bunun için bir not çıkar; *Main photo* seçerseniz yenisi kullanılır.
- Bir sorun olursa aynı yerdeki **Undo import** düğmesi aktarılanları Çöp Kutusu'na taşır ve eski hâline döner.

## Adımlar

1. **WP admin → References → Add New** sayfasını açın.
2. **Başlık** (en üstteki alan) ve **metin** (büyük editör) İngilizce yazılır. İkisi de zorunludur.
   - Metinde ara başlık için *Heading 2* veya *Heading 3* kullanın. *Heading 1* kullanmayın; sayfa başlığı zaten H1'dir
     (kullanılırsa otomatik olarak H2'ye çevrilir).
   - Fotoğraf eklemek için **Add Media** düğmesini kullanın. Fotoğraflar Medya Kütüphanesi'ne yüklenir.
   - Bir YouTube adresini tek başına bir satıra yapıştırırsanız video olarak gösterilir.
3. **Main photo** (sağ altta): kapak fotoğrafı. **Zorunludur.** Yatay ve en az 1600 px genişliğinde olmalı. Kapakta,
   listedeki kartta ve paylaşım görselinde bu fotoğraf kullanılır. Fotoğrafın *Alt Text* alanını Medya Kütüphanesi'nde
   doldurun.
4. **Excerpt** kutusu: başlığın altında ve kartta görünen 1–2 cümlelik özet. Boş kalırsa metnin ilk cümlesi kullanılır.
5. **Reference details** kutusu:
   - **Type**: *Project* (listede kart, ana sayfa ve ürün sayfaları) ya da *Company news* (/references/ altındaki
     "Company news" listesi).
   - **Address**: sayfa adresi (`/references/…/`). Boş bırakılırsa başlıktan üretilir. Sitede kullanılan bir adres
     seçilirse sonuna sayı eklenir (ör. `oman` → `oman-2`) ve bir uyarı çıkar.
   - **Country**: kartta, bilgi kutusunda ve /references/ filtresinde görünür. Listede olmayan yeni bir ülke
     seçilirse filtreye kendiliğinden eklenir. Ülke adları bütün dillerde otomatik çevrilir.
   - **Court type**: en fazla 2 tane. Sayfada ürün sayfalarına link olur ve referans o ürün sayfasının "kurulan
     projeler" bölümünde (en fazla 3, en yeniler) görünür.
   - **SEO title / Meta description**: isteğe bağlı. Boş kalırsa başlık ve özet kullanılır. Sayaç 60 / 158 karakteri
     geçince kırmızıya döner.
   - **Gallery**: metnin altında gösterilecek fotoğraflar. **Add photos** ile seçin, sürükleyerek sıralayın. Kapak
     fotoğrafı ve metinde zaten kullanılan fotoğraflar galeride tekrar gösterilmez.
6. **Çeviriler** (isteğe bağlı): *Türkçe (TR)*, *Deutsch (DE)*, *Français (FR)*, *العربية (AR)*, *Русский (RU)*
   kutularını açıp başlık, özet ve metni girin.
   - Bir dilin sayfası yalnızca **başlık ve metin** doldurulmuşsa oluşur.
   - Çevirisi olmayan dillerde /tr/references/ gibi listelerde İngilizce kart görünür ve İngilizce sayfaya gider.
   - Fotoğraflar bütün dillerde aynıdır.
7. **Save Draft** ile kaydedin, sonra sağdaki **Pages and preview** kutusunda dilin yanındaki **Preview**'a tıklayın.
   Önizleme kaydedilmiş son hâli gösterir; değişiklikten sonra önce tekrar kaydedin.
8. **Publish**. İngilizce başlık, metin veya kapak fotoğrafı eksikse referans yayınlanmaz, taslak olarak kalır ve
   neyin eksik olduğu yazılır.

## Yayından sonra

- Proje hemen şu yerlerde görünür: /references/ listesinin en başı, ana sayfadaki "referanslar" bölümü (en yeni 3),
  seçilen kort tiplerinin ürün sayfaları, sitemap ve llms.txt. Haberler /references/ altındaki haber listesine girer.
- Ziyaretçinin tarayıcısında sayfa önbellekte kaldıysa yenisi **5 dakikaya kadar** gecikebilir. Önbellek eklentileri
  (LiteSpeed, WP Rocket vb.) her kayıtta otomatik temizlenir.
- **Düzenleme:** referansı açın, değiştirin, **Update**.
- **Sıra:** yeni referanslar yayın tarihine göre sıralanır (en yeni en üstte). Aktarılan 42 referans eski listedeki
  sıralarını korur.
- **Adres değiştirme:** eski adres yeni adrese otomatik yönlenir (301).
- **Kaldırma:** **Move to Trash**. Referans siteden hemen kalkar. Çöp Kutusu'ndan geri alınabilir.

## Bilinmesi gerekenler

- Dünya küresindeki pinler ayrı bir veriden gelir. Yeni referans küreye eklenmez.
- Bir şey görünmüyorsa **Settings → Unixpadel Static → Rebuild references list** düğmesine basın.
- Sitenin tasarımı değiştiğinde eklenti yeniden derlenip yüklenir. Paneldeki referanslar olduğu gibi kalır ve yeni
  tasarımla gösterilir.
