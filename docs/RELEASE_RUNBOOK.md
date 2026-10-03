# Release Runbook

Bu runbook, `kefyusuf/laravel-shard` package release'lerini güvenli şekilde
kapatmak için kullanılır. (Consumer-repo dispatch otomasyonu kaldırıldı —
consumer doğrulaması artık manueldir.)

Mimari gereksinimler, üretim hazırlığı ve tüketici uygulaması rollout kapıları
için [Üretim Yol Haritası](PRODUCTION_ROADMAP.md) belgesini kullanın. Paket yayını
tek başına tüketici verisinin güvenli şekilde canlıya taşındığını doğrulamaz.

## 1) Package Pre-Release

1. `main` green olmalı (`tests`, `code-quality`, `docker-tests`, `consumer-smoke`).
2. `composer.json` version constraint / public API değişiklikleri kontrol edilmeli.
3. BC break varsa CHANGELOG'da "Removed"/"Deprecated" olarak işaretlenmeli ve
   major/minor sürüm kararı buna göre verilmeli.

## 2) Package Release

1. Package repo'da release tag olustur (örnek: `v5.0.1`).
2. GitHub Release publish et (CHANGELOG bölümünden notlarla).
3. Release sonrası CI koşularını (`tests`, `code-quality`, `docker-tests`,
   `consumer-smoke`) yeşil olarak doğrula.

## 3) Manual Verification

Package repo (local checks):

```bash
docker compose run --rm app vendor/bin/phpunit
docker compose run --rm app vendor/bin/phpstan analyse --memory-limit=1G
```

Packagist doğrulaması:

```bash
composer show kefyusuf/laravel-shard --latest
# ya da taze bir Laravel uygulamasına kurup smoke çalıştır:
php vendor/kefyusuf/laravel-shard/examples/smoke.php /path/to/your-app
```

## 4) Troubleshooting

### CI'da `composer update` exit 100

Packagist CDN geçici 502 verebilir (log'da `could not be downloaded (HTTP/2 502)`).
Kod değil altyapı sorunudur: birkaç dakika bekleyip `gh run rerun <id> --failed`.

### Release sonrası CI kırmızısı

Önce koşunun ilgili commit'e ait olduğunu doğrula (`gh run list --branch main`),
paralel/eski koşu kayıtları yanıltabilir.
