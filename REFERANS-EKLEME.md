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

### Seçenek A: AI Asistanı ile Hızlı Ekleme (Önerilen — En Kolay Yol)
1. **WP admin → References → Add New** sayfasını açın.
2. Sayfanın en üstündeki **AI Assistant & Quick Draft** kutusuna projenizle ilgili kısa notları (Türkçe veya İngilizce) yazın:
   *Örnek:* `Viyana Avusturya'da Vienna Padel Club için 4 adet Full Panoramic kort kuruldu. Mavi suni çim, 200W LED aydınlatma, anahtar teslim proje.`
3. **Generate Draft & All Languages** düğmesine tıklayın:
   - Gemini AI; İngilizce başlığı, özetini, alt başlıklı İngilizce makale metnini, ülkesini (`Austria`) ve kort tipini (`Full Panoramic Padel Court`) otomatik oluşturur.
   - Aynı anda **Türkçe (TR), Deutsch (DE), Français (FR), العربية (AR) ve Русский (RU)** çevirilerini de otomatik olarak ilgili dil kutularına doldurur.
4. Fotoğraflarınızı **Gallery** bölümünden **Add photos** ile seçin (kapak fotoğrafı seçmediyseniz galerideki ilk fotoğraf otomatik olarak ana kapak fotoğrafı yapılır).
5. **Publish** düğmesine basın. Referans tüm dillerde anında yayına girer!

---

### Seçenek B: Klasik / Elle Ekleme

1. **WP admin → References → Add New** sayfasını açın.
2. **Başlık** ve **metin** alanlarını İngilizce girin. (Başlığı yazarken ülke ve kort tipi başlıktan otomatik algılanıp seçilir).
3. **Gallery** bölümünden fotoğrafları ekleyin (veya sağ alttan *Main photo* belirleyin).
4. **Çeviriler:**
   - **Tek Tıkla Çeviri:** *Reference details* kutusundaki **Translate to All Languages** düğmesine tıklayarak tüm dilleri anında doldurabilirsiniz.
   - **Veya Otomatik:** Çeviri kutularını tamamen boş bırakırsanız, **Publish** veya **Save Draft**'a bastığınızda sistem eksik dilleri arka planda kendisi otomatik çevirir.
   - İsterseniz belirli dilleri elle yazabilir veya otomatik çevrilenleri düzeltebilirsiniz.
5. **Publish**. Referansınız tüm dillerde yayına girer.

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
