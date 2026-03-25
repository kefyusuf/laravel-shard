# Release Runbook (Package + Consumer)

Bu runbook, `yusuf.kef/laravel-redis-shard` package release'lerini
consumer repo ile birlikte güvenli şekilde kapatmak icin kullanilir.

## 1) Package Pre-Release

1. `main` green olmalı (`tests`, `code-quality`).
2. Consumer PR CI (branch pin) green olmalı.
3. `composer.json` version constraint/public API degisiklikleri kontrol edilmeli.

## 2) Package Release

1. Package repo'da release tag olustur:
   - ornek: `v3.0.1`
2. GitHub Release publish et.
3. Package workflow `Dispatch Consumer Post Release` otomatik calismali.

Gereken secret (package repo):

- `CONSUMER_WORKFLOW_TOKEN` (scope: `repo`, `workflow`)

## 3) Consumer Post-Release Validation

Beklenen otomatik tetiklenen workflow:

- `Consumer Integration Post Release`

Beklenen inputs:

- `package_tag`: release version (ornek `3.0.1`)
- `package_repo_url`: `https://github.com/<owner>/<package-repo>.git`

Basari kriteri:

1. Docker health green
2. `integration:smoke` green
3. `php artisan test` green

## 4) Consumer Main Pin Policy

`main` branch icin kural:

- Package dependency `dev-*` olamaz.
- Stable tag pin zorunlu (`3.0.x` / `3.x.y`).

Kontrol workflow:

- `Main Stable Pin Guard`

## 5) Incident Triage

### A) Docker Hub 500

- Retry/backoff script tarafinda mevcut (`bin/ci-integration.sh`).
- Tekrar calistir.

### B) Repo access/package fetch

- `PACKAGE_REPO_TOKEN` secret kontrol et (consumer repo).
- `PACKAGE_REPO_URL` variable kontrol et.

### C) JSON parse failures

- Komutu `--format=json` ile dogrula.
- stdout'ta table/log satiri olmamali.

## 6) Manual Verification Commands

Consumer repo:

```bash
docker compose up -d --build mysql redis app
./bin/ci-integration.sh
```

Package repo (local checks):

```bash
composer update --with-all-dependencies
composer test
```
