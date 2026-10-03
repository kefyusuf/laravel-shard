# Üretim hazırlığı ve geliştirme yol haritası

Araştırma tarihi: 2 Ekim 2026. İncelenen yerel taban: `main`, `f69bab6`; son yerel sürüm etiketi: `v5.0.0`.

Scoped patch update (3 October 2026): v5.0.1 rejects multiple source rows for a
move key, uses atomic snapshot predicates for fenced source deletion, and
restores keyed queue context from the current mapping without rewriting it.
Each issue was reproduced by a failing regression before its fix, including
a case-insensitive SQLite column. The original assessment below remains a
baseline: these fixes do not complete R1/R2/R4 or milestones M1/M2. Online
resharding, distributed metadata, worker cleanup, and production DB validation
remain outside this patch. See [Known limitations](../README.md#known-limitations).

Bu belge mevcut kod, test ve CI tanımlarının incelenmesi ile resmi teknik kaynakların karşılaştırmasına dayanır. Buradaki riskler çalışma zamanı testleriyle yeniden üretilmiş bulgular değildir. CI dosyalarının varlığı, mevcut koşuların başarılı olduğunu kanıtlamaz. Üretim kapasitesi veya güvenilirlik sertifikası verilmemektedir.

## 1. Ürün konumu ve kapsam

Önerilen konum: Laravel uygulamalarının shard anahtarı bilinen işlemlerini yöneten, operasyon sözleşmesi açık bir Composer paketi. İlk hedef için geçici varsayım: tenant bazlı SaaS, MySQL/InnoDB, sabit topoloji ve kontrollü pilot. Kullanıcının hedefi netleşince motor, veri modeli ve kabul eşikleri güncellenmelidir.

Sharding kararı veri hacmi, yazma yükü ve büyüme ölçümlerine dayanmalı; önce indeksleme, sorgu optimizasyonu, cache, read replica ve dikey ölçekleme seçenekleri değerlendirilmelidir. Microsoft, sharding'in kalıcı operasyon karmaşıklığını ve uygun olmayan iş yüklerini açıkça ele alır. [Microsoft: Sharding pattern](https://learn.microsoft.com/en-us/azure/architecture/patterns/sharding).

Paketin sorumluluğu:

- Güvenli yönlendirme, tenant yerleşimi, sorgu destek sözleşmesi ve tutarlılık seçenekleri.
- Harita sürümü, kontrollü taşınma, doğrulama, devam etme ve operasyon raporları.
- Laravel request/job yaşam döngüsü, ölçülebilir hatalar ve test edilebilir adaptörler.

Uygulama/altyapı ekibinin sorumluluğu:

- Tenant yetkilendirme ve veri filtreleme; doğru shard seçimi tek başına tenant izolasyonu sağlamaz.
- Veritabanı HA, yedekleme/PITR, secret yönetimi, ağ/TLS, kapasite ve nöbetçi operasyon.
- İşlem idempotency'si, outbox/reconciliation ve uygulamaya özgü iş kuralları.

İlk sürüm hedefi dışındaki işler: tam dağıtık SQL motoru, genel cross-shard JOIN, XA/2PC, otomatik failover/provisioning ve aktif-aktif çok bölgeli yazma. Vitess/Citus burada özellik kopyalama listesi değil, garanti ve operasyon disiplini için referanstır.

## 2. Sektörel beklenti ile mevcut durum

| Alan | Beklenti | Mevcut sistem | Karar |
| --- | --- | --- | --- |
| Veri modeli | Değişmez shard anahtarı, ilişkili tabloların aynı yerde tutulması, kimlik/unique/FK sözleşmesi | Model bazlı shard key ve tenancy bridge var; hash stratejileri tablo adını da kullanıyor | Tenant yerleşimini tablo yönlendirmesinden ayır |
| Sorgu güvenliği | Desteklenmeyen sorguları önceden reddetme; veri kaybı veya yanlış shard'a yazma olmaması | Fail-fast altyapısı ve saf routing planı var; predicate analizi ilk eşleşmede dönebiliyor | Boolean/nested sorgu matrisi ve gerçek DB regresyonları |
| Metadata | Ortak, dayanıklı, sürümlü otorite; cache yalnızca türetilmiş veri | Redis locator, fallback ve dosya registry var | Çok sunuculu kullanım için harita protokolü ve ortak otorite |
| Rebalance | Kopyala, değişiklikleri yakala/engelle, doğrula, yönlendir, sonra temizle | Dry-run, limit, idempotent row mover ve yeniden okuma kontrolü var | Mevcut akış online güvenlik garantisi için yeterli değil |
| Replica | Yazma sonrası okuma ve transaction/locking politikası | Ayrı replica bağlantısına yönlendirme ve modelin primary'ye geri bağlanması var | Primary okuma seçeneği ve transaction/sticky politikası |
| Cross-shard transaction | Kısmi commit davranışının açıklığı ve kurtarma yöntemi | Best-effort semantik doğru şekilde belgelenmiş | Varsayılan single-shard transaction; reconciliation örneği |
| Uzun yaşayan süreç | Request/job/tenant sınırlarında durum temizliği; taşınma sonrası eski job güvenliği | Octane locator reset ve Pulse flush var; queue eski bağlantıyı yeniden register edebiliyor | Sürümlü context, güncel routing ve finally temizliği |
| Şema yönetimi | Shard bazında migration durumu, drift tespiti, yeni shard şema eşitliği | Provisioning ve paket metadata migration'ı var | Uygulama şeması için orchestrator/preflight |
| Gözlemlenebilirlik | Gecikme, hata, trafik, doygunluk; metadata/replica/migration metrikleri | Health, diagnostic report, monitor ve Pulse var | SLO, alarm ve yapılandırılmış olay sözleşmesi |
| Doğrulama | Gerçek motor, çok süreç, hata enjeksiyonu ve kapasite testleri | Testbench/SQLite ağırlıklı testler; PHP/Laravel/modül CI matrisi var | Pilot motoruyla gerçek entegrasyon ve kurtarma testleri |
| Yayın | İncelenen commit'in tüketici testleri ve yayımlanan exact tag doğrulaması | Consumer smoke önce Packagist paketini kuruyor; başarısızsa yerel path'e dönüyor | Yerel candidate ve published-tag testlerini ayrı zorunlu işler yap |
| Operasyon | Tutarlı backup/restore, canary, cutover, rollback ve olay müdahalesi | Package release runbook var | Consumer üretim geçişini ayrı kapılarla yönet |

Citus'un co-location yaklaşımı ilişkili tabloların aynı tenant anahtarıyla aynı düğümde tutulmasını temel alır. Mevcut `table:key` hash'inden tenant yerleşimi garantisi çıkarılamaz. [Citus: Choosing Distribution Column](https://docs.citusdata.com/en/stable/sharding/data_modeling.html).

Vitess'in taşıma akışı veri doğrulama ile trafik değişimini ayırır; ters trafik için reverse replication kullanır. Paket için ilk aşamada yazması durdurulmuş taşınma, online CDC taşınmasından daha küçük ve doğrulanabilir bir hedeftir. [Vitess: Reshard](https://vitess.io/docs/23.0/reference/vreplication/reshard/), [Vitess: VDiff](https://vitess.io/docs/23.0/reference/vreplication/vdiff/).

## 3. Üretim öncesi doğrulanacak kritik kod riskleri

P0: ilgili üretim kullanımını engelleyen veri doğruluğu işi. P1: genel üretim işletimini tamamlayan iş. P2: ölçümler ve pilot sonrasında ölçek/ergonomi işi. Bunlar projenin öncelikleridir; güvenlik zafiyeti sınıflandırması değildir.

### R1 — Rebalance yazma/silme yarışı ve erken yönlendirme (P0)

Kanıt: `src/Rebalance/DatabaseRebalanceDataMover.php` kaynak satırı yeniden okur, ardından yalnız anahtar koşuluyla siler. Yeniden okuma ile silme arasında yazma gerçekleşirse kontrol bunu kapsamaz. Fence tetiklenince kaynak korunur fakat mover `true` döner; `RebalanceShardCommand` başarılı sonuç için locator'ı hedefe taşır. Sonraki koşu locator üzerinden planlandığı için kaynakta kalan satırın tekrar temizleneceği garanti değildir.

İş: taşınma durumunu kalıcılaştır; kaynak silmesini versiyon/CAS veya motorun transaction/lock mekanizmasıyla koru; uygulama yazmaları ve önceden hydrate edilmiş modellerin cutover sırasında nasıl yönetileceğini tanımla. Boolean başarı yerine `copied`, `deferred`, `verified`, `cutover`, `cleaned` ayrımı kullan. Koşullu silme tek başına tüm online taşınma protokolünü çözmez.

Kabul: yeniden okuma sonrasındaki eşzamanlı yazma kaybolmaz; deferred move routing'i erken değiştirmez; her crash noktasından resume edilir. README ve rebalance belgesindeki koşulsuz “yazma asla kaybolmaz” iddiası doğrulanmış sözleşmeyle değiştirilir. İlk pilotta canlı rebalance kapalıdır.

### R2 — Tenant anahtarı ile satır kimliğinin karışması (P0, tenant taşınması)

Kanıt: bundled mover `where(keyColumn, key)->first()` ile tek satır alır, aynı koşulla `delete()` yapar. `keyColumn=tenant_id` gibi unique olmayan kolonla kullanılırsa tek satır kopyalayıp birden fazla kaynak satırını silme riski vardır. Trait de kayıt silindiğinde ortak shard-key eşlemesini `forget` eder.

İş: row key ile placement key'i ayrı kavramlar yap; bundled row mover'ın unique key ön koşulunu doğrula. Tenant mover bütün ilgili tabloları PK bazında chunk'lasın; locator lifecycle'ı tenant kaydının yaşamına göre yönetilsin.

Kabul: aynı tenant'a ait çok satır/tüm ilişkili tablolar eksiksiz taşınır; bir kayıt silmek kalan tenant kayıtlarının mapping'ini bozmaz. Bu destek tamamlanmadan tenant rebalance sunulmaz.

### R3 — Harita güncellemelerinde atomiklik ve dayanıklılık (P0)

Kanıt: Redis `register` ve `forget` birden fazla ayrı komut çalıştırır; command cutover'da önce forget sonra register yapar. `VirtualBucketStrategy::determine` ilk atamada lock dışındaki snapshot'ı `writeBucketMap` ile yazar; atomik `mutateBucketMap` CLI'da mevcut olsa da bu yol onu kullanmaz. Registry `upsert` de read-modify-write'ın tamamını kilitlemez. Dosya cache'i yalnız `mtime` ile izlenir; bozuk JSON boş registry gibi yorumlanabilir.

İş: tek mapping ve ters indeks geçişini atomik yap; Redis Cluster destekleniyorsa çok anahtarlı atomik işlemlerin slot şartlarını tasarla. Harita generation/CAS, doğrulama ve cache invalidation ekle; bozuk/eksik otoriteyi sessiz yeni yerleşime dönüştürme. Ortak SQL metadata store veya eşdeğer dayanıklı otorite için ADR hazırla. Tek makine file adapter'ını ayrıca destekle.

Kabul: paralel ilk atamalar ve provisioning kaybolmaz; iki app instance aynı generation'ı görür; corrupt registry/failover sırasında yanlış shard'a yazılmaz. Mapping kaybında yeniden üretim/restore prosedürü test edilir.

Redis'in `WAIT` komutu dahi tek başına güçlü tutarlılık sağlamaz; TTL kaldırılması da disk dayanıklılığı sağlamaz. Metadata otoritesi için RPO ve kurtarma modeli açıkça seçilmelidir. [Redis: WAIT](https://redis.io/docs/latest/commands/wait/), [Redis: Persistence](https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/).

### R4 — Taşınma öncesi queue context'inin geri yazılması (P0, queue ile taşınma)

Kanıt: `RestoreShardContext::apply` payload'daki bağlantıyı request context'ine koyar ve key varsa locator'a tekrar register eder. `A` shard'ında oluşturulan job, kayıt `B`'ye taşındıktan sonra mapping'i tekrar `A`'ya çevirebilir. Middleware `handle` context'i `finally` ile geri yüklemiyor.

İş: job payload'ı authoritative mapping yazmasın; logical key/generation ile güncel bağlantıyı çöz. Eski context için reroute veya açık retry/fail politikası tanımla. Request/job success/failure/retry sınırlarında context, locator ve metric state'ini temizle.

Kabul: eski job mapping'i geri alamaz; arka arkaya farklı tenant job'ları ve exception sonrası shardsız job birbirinden izoledir. Wildcard/request-only context'in tenant taşıması davranışı da test edilir.

### R5 — Sorgu planının erken kabulü (P0)

Kanıt: `ShardRoutingPlan::from` ilk shard-key `=`/`IN` predicate'inde döner. Sonraki `orWhere` veya başka bir kolondaki OR bütün mantıksal ifadeyi genişletebilir; mevcut plan bunu incelemeden kabul edebilir. Mevcut OR unit testi shard anahtarı üzerindeki tek OR predicate'ini sınar.

İş: predicate ağacını bütün olarak güvenli analiz et veya kanıtlanamayan OR/nested/raw desenlerini reddet. Join, eager loading, ilişkiler, qualified columns, empty IN, duplicate ID, upsert ve toplu yazmalar için destek tablosu yayınla.

Kabul: `where(shard_key, A)->orWhere(shard_key, B)` ve `where(shard_key, A)->orWhere(status, X)` kısmi sonuç/yanlış yazma üretmez; desteklenmeyen işlem herhangi bir shard'a yazmadan hata verir.

### R6 — Replica/transaction okuma sözleşmesi (P0, replica açık pilot)

Kanıt: `ReadReplicaResolver` yapılandırılmış replica'yı koşulsuz seçer. Ayrı connection kullanımı için paket düzeyinde write-after-read state veya transaction/locking guard görünmüyor. Hydrate edilmiş modeli primary'ye bağlamak okumanın tazeliğini sağlamaz.

İş: primary okuma seçeneği, işlem içinde primary ve request/job içinde yazma sonrası primary politikası; replica lag/unavailable davranışı. Kilitli okumaların replica'ya gitmesini engelle.

Kabul: yazmadan hemen sonraki okuma ve transaction içindeki okuma sözleşmesine uyar; kasıtlı replica gecikmesiyle test edilir. İlk pilotta replica kapalı tutulabilir. Laravel'in native `sticky` davranışı aynı request'te yazma sonrası primary okuması sunar; ayrı shard/replica bağlantılarıyla bunun otomatik çalıştığı varsayılmamalıdır. [Laravel: Read and Write Connections](https://laravel.com/framework/docs/13.x/database#read-and-write-connections).

## 4. Aşamalı backlog ve çıkış kapıları

Takvim tahmini yerine bağımlılık ve kabul kapıları kullanılır. Başarı test raporu, ölçüm ve tatbikat kayıtlarıyla gösterilir; tarih veya version numarası tek başına kapı açmaz.

| Aşama | İşler | Bağımlılık / çıkış koşulu |
| --- | --- | --- |
| M0 — Sözleşme | Pilot motor/tenant modeli; shard key, PK/unique/FK ve co-location ADR; metadata otoritesi ADR; desteklenen sorgular; SLO/RPO/RTO; API uyumluluğu | Kullanım sınırları ve tüketici operasyon sahibi belli |
| M1 — Doğruluk | R1–R6; önce en küçük regresyonlar, sonra gerçek motor/çok süreç testleri; metadata ve context lifecycle düzeltmeleri | Pilotun etkin yollarında açık P0 yok; ertelenen modüller kapalı ve açıkça destek dışı |
| M2 — Operasyon | Preflight/doctor; shard schema drift; migration resume; backup/restore manifest'i; yapılandırılmış olaylar; metrics/alarm; exact candidate ve exact release smoke | Gerçek motorla restore ve hata tatbikatı başarılı; yayınlanacak artefakt doğrulanmış |
| M3 — Kontrollü üretim | Sabit topoloji, primary-only, küçük tenant kohortu; doğrulanmış consumer; shadow routing ve canary; izleme/rollback | İş yüküne göre belirlenen gözlem süresi ve SLO kapıları başarılı |
| M4 — Yönetilen taşınma | Kalıcı job journal; chunk/checkpoint/throttle; tenant/table-group mover; verify/cutover/cleanup; source retention | Önce tenant yazması durdurulan taşınma ve reversal tatbikatı başarılı |
| M5 — Online ölçek | İhtiyaç varsa CDC/change log, delta catch-up ve sürümlü writer fencing; bounded fan-out; hot tenant analizi; kapasite bazlı plan önerileri | Online move için concurrent/crash/recovery kanıtı; otomatik aksiyon ayrıca kararlaştırılır |

M2 backlog ayrıntıları:

- DB motoru seçimine göre MySQL çok connection integration; PostgreSQL desteği vaat edilecekse ayrı container/driver/semantik testleri. SQLite hızlı testleri korunur.
- Çok process ilk kayıt, mapping taşıma, stale cache, network timeout, Redis unavailable/restart, worker kill, mid-commit failure ve rebalance her checkpoint'inde kill/retry testleri.
- Multi-node consumer: iki app instance, HTTP ve queue, gerçek schema migration, aynı artefakt ve aynı metadata otoritesi.
- Existing consumer smoke'u ikiye ayır: PR/commit için zorunlu local-path candidate test; release için exact tag Packagist install, kurulu sürüm doğrulama ve fallback olmadan test. Path fallback, yayımlanan paket doğrulamasında başarı sayılmaz.
- `SECURITY.md` sürüm desteği tablosu 1.x/2.x içeriyor; bakım politikası belirlenip güncellenmeli. `tests/README.md`, benchmark sayıları ve CHANGELOG deprecation ifadeleri gerçekle eşleştirilmeli.
- Audit: kim, hangi plan/generation, kaynak/hedef, veri doğrulama sonucu ve cutover zamanı. Health çıktısında secret/PII bulunmamalı; health endpoint erişimi kontrol edilmeli.
- Lifecycle durumları: active, draining, unavailable, retired; yeni tenant kabulü ve mevcut mapping erişimi ayrı davranışlar. Arızalı shard'daki veriyi başka shard'da varmış gibi route etmek failover değildir.

M5'ten önce genel cross-shard query'ler için limit/bellek/timeout/fan-out bütçesi gerekir. Mevcut `CrossShardQueryBuilder::get` tüm shard sonuçlarını uygulama belleğinde birleştirir. Global ORDER BY/pagination, local ID çakışmaları, aggregate semantiği ve kısmi hata politikası test edilmeden büyük veri kapasitesi iddiası yapılmamalıdır. Ağır analitik için ayrı veri hattı değerlendirilebilir.

Otomatik provisioning, konsol presenter refaktörü ve yönetim arayüzü bu kapıları geçmenin önüne alınmaz. Hot tenant'ın yükü tek shard'ı aşıyorsa yalnız bucket sayısını artırmak çözüm değildir; entity/subpartition veya farklı yerleşim modeli ayrıca değerlendirilir.

## 5. Doğru üretime geçiş akışı

Paket yayımlamak ile bir tüketici uygulamasını canlıya geçirmek iki ayrı süreçtir. Paket yayını için [Release Runbook](RELEASE_RUNBOOK.md) kullanılır. Aşağıdaki akış tüketici veri yolunun devreye alınması içindir.

### G0 — Ön koşullar

1. Sharding gerekçesi ve gerçek query envanteri; tenant/schema/kimlik kararı; desteklenmeyen yolların listesi.
2. Motor, peak yük, hot tenant, veri hacmi, p95/p99 bütçesi, hedef SLO ve kabul edilen RPO/RTO değerleri.
3. Test edilmiş immutable uygulama image'ı ve Composer lock; exact paket sürümü; config ve topology generation manifest'i.
4. Veritabanı ve metadata backup'ları; ortak recovery noktası veya mapping yeniden oluşturma yöntemi; restore tatbikatı. Rebalance devam ederken bağımsız snapshot'ların tutarlı olduğu varsayılmaz.
5. TLS/private network, dar yetkili uygulama hesabı, migration hesabı ayrımı; secret'ların registry/report çıktılarında yönetimi. Dev Compose production deployment değildir: mevcut Redis servisinde persistence kapalıdır ve host portu açıktır.

### G1 — Staging ve prova

Üretime benzer çok node/DB/queue ortamında gerçek motorla schema drift, routing, concurrency, backup restore, stale job, restart ve rollback denenir. Veri anonimleştirilir. Önce sabit topoloji ve primary-only yol sınanır.

Yük testi tek strategy ops/s ile sınırlanmaz: tenant dağılımı ve boyutu, QPS, write/read oranı, p95/p99, hata oranı, DB connection/disk doygunluğu, metadata latency ve uzun worker bellek kullanımı ölçülür. Kabul eşikleri iş yüküne göre belirlenir; varsayılan bir sayı “sektör standardı” diye sunulmaz.

### G2 — Mevcut veriyi hazırlama

Fresh tenant pilotu tercih edilebilir. Mevcut verinin taşınacağı durumda ilk güvenli seçenek hedef tenant'ın HTTP, job, scheduler ve harici yazmalarını durdurmaktır. Kaynak authoritative kalır; bütün ilişkili tablolar checkpoint'li kopyalanır, PK/content karşılaştırılır. Sadece satır sayısı eşitliği yeterli değildir.

İlk rollout için doğrulanmış yeni consumer yeterlidir; online data migration ilk canlı geçiş şartı değildir. Yazmayı durdurmadan taşınma gerekiyorsa M5 tamamlanmadan bu yol açılmaz.

### G3 — Shadow ve cutover

1. Eski yol authoritative iken shadow routing farklarını kaydet; shadow işlemler mapping yazmamalı ve kontrolsüz çift yazma yapılmamalı.
2. Küçük, seçilmiş tenant kohortunu feature flag ile aç. İlişkili istek/job'lar aynı tenant yerleşimini kullanmalı; tenant'ı rastgele istek bazında iki write path'e bölme.
3. Cutover öncesi bütün writer'ları drain et; veri farkları sıfır; schema ve mapping generation eşit; durable routing geçişi yapılabilir durumda olsun.
4. Trafiği/güncel job routing'ini hedefe al; kaynak veriyi hemen silme; cohort metriklerini kontrol grubuyla karşılaştır.

Canary değişikliği sınırlı trafik ve sınırlı sürede ölçerek genişletme kararını verir. Buradaki tenant kohortu seçimi sharding veri sahipliğine uyarlamadır. [Google SRE: Canarying Releases](https://sre.google/workbook/canarying-releases/).

### G4 — Genişletme ve rollback

- Yanlış shard/tenant erişimi, eksik veri veya mapping generation ayrışması görülürse rollout hemen durur; yeni geçişler kapatılır ve etkilenen yazmalar fence edilir. Gecikme/hata/lag alarm eşikleri ölçülen baseline/SLO'ya göre önceden tanımlanır.
- Code rollback ile data rollback farklıdır. Hedefte yeni yazma yoksa doğrulanmış eski mapping'e dönüş yapılabilir. Hedef yazma aldıysa eski kaynak stale olabilir: yazmalar durdurulur, delta/reconciliation uygulanır ve doğrulanmış veri sahipliğine göre dönüş yapılır. Sadece feature flag kapatmak güvenli veri rollback'i değildir.
- Rollout ancak önceki kohort kapıları başarılıysa genişler. Cleanup, rollback penceresi kapandıktan ve retention/backup şartları karşılandıktan sonra ayrı operasyon olarak yapılır.

### G5 — İşletim

Latency, traffic, errors ve saturation metrikleri yanında routing failure, metadata cache/fallback kullanımı, replica lag, generation uyuşmazlığı ve pending/deferred move izlenir. Tenant ID metric label'ı olarak kontrolsüz kullanılmaz; yüksek cardinality olay/log tarafında yönetilir. [Google SRE: Monitoring Distributed Systems](https://sre.google/sre-book/monitoring-distributed-systems/).

Olay sahipliği, escalation ve runbook'lar belirlenir. Restore/Redis kaybı/stale job/yarım cutover tatbikatları tekrarlanır. Paket için hata sonrası açıklanabilir ve kurtarılabilir durum, yalnız başarılı HTTP health kontrolünden daha geniş bir kabul şartıdır.

## 6. Uygulama sırası ve henüz verilmemiş kararlar

İlk iş sırası: **M0 sözleşmesi → R5 sorgu güvenliği → R3 metadata atomikliği → R4 context güvenliği → R1/R2 taşınma semantiği → R6 replica politikası → M2 doğrulama/operasyon → M3 pilot**. R1/R2 ve R6 tamamlanmadan bu modüller açık pilot yapılmaz. BC break gerektiren API değişiklikleri ADR'den sonra semver ile planlanır; sırf milestone tamamlandı diye major sürüm seçilmez.

Her işin teslimi: küçük regresyon senaryosu, Docker'da uygun motor testi, ilgili modül matrisi, PHPStan/biçim kontrolleri, sözleşme dokümanı ve operasyon kabul kanıtı. Döküman değişikliği için anlamsız runtime testi eklenmez.

Üretim hedefi belirlenmeden cevaplanamayacak kararlar: tenant mı row/entity mi; pilot motoru; ölçülen büyüme/yük; mevcut verinin taşınma gereği; kabul edilen kesinti/RPO/RTO; metadata HA tercihi; sorumlu consumer ve operasyon ekibi. Bu belge geçici MySQL/SaaS varsayımına bağlı öneriler içerir, sabit teslim tarihi veya üretime çıkış onayı içermez.
