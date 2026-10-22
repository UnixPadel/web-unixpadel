# Projeyi canlıya alma (GitHub → WordPress)

`main` dalına yapılan her push birkaç dakika içinde www.unixpadel.com'a kendiliğinden yüklenir. ZIP derleyip elle
yüklemek gerekmez. WordPress panelinin dili İngilizce olduğu için düğme ve alan adları aşağıda İngilizce yazıldı.

## Nasıl çalışır

1. Kodu `main`'e push edersiniz.
2. GitHub Actions (`.github/workflows/deploy-wp.yml`) siteyi derler, eklenti ZIP'ini üretir ve bunu bir **Release**
   (`wp-0.1.0.…`) olarak yayınlar. Build hata verirse Release oluşmaz ve canlı siteye dokunulmaz.
3. GitHub, WordPress'e imzalı bir "yeni sürüm var" çağrısı yapar. Eklenti (`wp-plugin/includes/github-deploy.php`)
   Release'i indirir, doğrular, kendini günceller ve sayfa önbelleğini temizler.
4. Çağrı siteye ulaşamazsa (ör. güvenlik duvarı engellediyse) eklenti saatte bir kendisi kontrol eder. Bu durumda
   güncelleme en fazla 1 saat gecikir.

Güncelleme sırasında References, teklif kayıtları, mod (off/preview/live) ve ayarlar korunur. Site birkaç saniye
"kısa süreliğine bakımda" sayfası gösterebilir.

## Bir kerelik kurulum

Bu adımlar yapıldıysa tekrarlamaya gerek yok. Kurulumun yapılıp yapılmadığını **Settings → Unixpadel Static** sayfasının
altındaki **GitHub deploy** bölümünden anlayabilirsiniz.

### 1. İlk ZIP'i elle yükleyin
1. GitHub'da repo → **Actions** sekmesinde son çalışmanın yeşil ✓ ile bittiğini kontrol edin.
2. Repo → **Releases** → en üstteki `wp-0.1.0.…` sürümünün **Assets** bölümünden `unixpadel-static-….zip`'i indirin.
   "Source code (zip)" dosyalarını indirmeyin; onlar derlenmemiş kaynak kodudur.
3. WP admin → **Plugins → Add New → Upload Plugin** → ZIP'i seçin → **Install Now** →
   **Replace current with uploaded**.

### 2. GitHub token'ı girin
WordPress, private repodan dosya indirebilmek için bu token'ı kullanır. Token sadece okuma yetkisine sahiptir.
1. GitHub → profil fotoğrafı → **Settings → Developer settings → Personal access tokens → Fine-grained tokens →
   Generate new token**.
2. Alanları şöyle doldurun:
   - **Token name:** `unixpadel-wordpress`
   - **Resource owner:** **UnixPadel** (kendi kullanıcı adınızı değil, organizasyonu seçin)
   - **Expiration:** 1 yıl
   - **Repository access:** Only select repositories → `web-unixpadel`
   - **Permissions → Repository permissions → Contents:** Read-only (başka izin vermeyin)
3. **Generate token**'a basın ve `github_pat_…` ile başlayan değeri kopyalayın. Bu değer sadece bir kez gösterilir.
   Organizasyon onay istiyorsa bir UnixPadel yöneticisinin token'ı onaylaması gerekir.
4. WP admin → **Settings → Unixpadel Static → GitHub deploy** → **GitHub token** alanına yapıştırın.
   **Automatic updates** işaretli olsun → **Save deploy settings**.
5. **Check for an update now**'a basın. "Already up to date" mesajı token'ın çalıştığını gösterir. "GitHub answered 404"
   mesajı token'ın yanlış olduğunu, repoya erişimi olmadığını veya onay beklediğini gösterir.

### 3. Deploy secret'ını GitHub'a ekleyin
1. Aynı bölümdeki **For GitHub** satırında uzun bir metin var; kutuya tıklayıp kopyalayın.
2. GitHub repo → **Settings** (repo ayarları, profil değil) → **Secrets and variables → Actions →
   New repository secret**.
3. **Name:** `WP_DEPLOY_SECRET`, **Secret:** kopyaladığınız metin → **Add secret**.

### 4. Deneyin
GitHub → **Actions → Deploy to WordPress → Run workflow** (`main`). Son adım olan **Tell WordPress to update**
çıktısında `{"ok":true,…}` görünmeli. Ayrıca WordPress ayar sayfasındaki kayıt listesinde **Updated** satırı çıkmalı.

## ⚠️ Her yıl: GitHub token'ını yenileyin

Token 1 yıllık oluşturulur. **Süresi dolunca site güncellenmeyi bırakır.** Canlı site çalışmaya devam eder, ama push'lar
siteye gelmez ve ayar sayfasının kayıt listesinde "GitHub answered 401/404" hataları görünür. GitHub süre dolmadan
birkaç gün önce e-posta ile hatırlatır. Yine de son tarihi takvime yazın.

**Yenileme (yaklaşık 5 dakika):**
1. GitHub → **Settings → Developer settings → Fine-grained tokens** → `unixpadel-wordpress` → **Regenerate token**.
   Yeni bir token da oluşturabilirsiniz; ayarlar yukarıdaki 2. adımla aynıdır.
2. Yeni `github_pat_…` değerini WP admin → **Settings → Unixpadel Static → GitHub token** alanına yapıştırın →
   **Save deploy settings**.
3. **Check for an update now** ile token'ın çalıştığını kontrol edin.

`WP_DEPLOY_SECRET`'ın süresi dolmaz. Onu sadece sızdığından şüphelenirseniz **New secret** ile değiştirin ve yeni
değeri GitHub'a tekrar girin.

## Sorun olursa

- **Yeni sürüm siteyi bozduysa:** ayar sayfasındaki **Roll back to wp-…** düğmesi önceki sürüme döner ve otomatik
  güncellemeyi durdurur. Sorun düzeltilip push edildikten sonra **Automatic updates** kutusunu tekrar işaretleyin.
  Acil durumda eklentiyi devre dışı bırakmak eski WordPress sitesine döndürür.
- **GitHub Actions kırmızı ✗ verdiyse:** build hatası vardır ve canlı siteye hiçbir şey gitmemiştir. Çalışmayı açıp
  kırmızı adımın çıktısına bakın.
- **Actions'ta "Automatic updates are paused" uyarısı:** WordPress'te otomatik güncelleme kapalıdır. Kutuyu tekrar
  işaretleyin.
- **"WordPress cannot write files" uyarısı:** hosting, WordPress'in dosya yazmasına izin vermiyordur. Hosting
  firmasından "direct" dosya yazma izni istenmeli.
