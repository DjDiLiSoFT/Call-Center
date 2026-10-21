# CallStats Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Отдельный веб-сервис на сервере FreePBX 16: статистика операторов, журнал оператора, записи разговоров по оператору — для одного пользователя, на русском.

**Architecture:** Cron-сборщик (`bin/collect.php`, раз в минуту) читает `queue_log` (файл) и `asteriskcdrdb.cdr` (только чтение), собирает из них «звонки» и «исход для каждого оператора» по единым правилам и пишет в собственную БД `callstats`. Веб-часть — простой PHP без фреймворка (`public/index.php` — роутер), читает только `callstats` и файлы записей. Логика подсчёта — чистые классы без БД, покрытые тестами на фикстурах в формате реальных данных.

**Tech Stack:** PHP 7.4 (без composer-зависимостей в рантайме, свой автозагрузчик), PDO MySQL, MariaDB 5.5, Apache, ванильный JS/CSS (всё локально). Тесты: PHPUnit 9.6. Локально: portable PHP 7.4.33 и portable MariaDB 5.5.68 в `tools/`.

**Spec:** `docs/superpowers/specs/2026-10-21-callstats-design.md` (особенно п. 5.3 и п. 10).

**Отступление от спеки, принятое при планировании:** CEL не используется. По реальным данным (п. 10) CDR хранит отдельную строку на каждую попытку дозвона до каждого оператора (очередь: `dcontext=ext-queues`, `dstchannel=Local/<ext>@from-queue-…`, `FAILED` = телефон не в сети; прямой: `dcontext=ext-local`, `dstchannel=PJSIP/<ext>-…`), а `queue_log.callid == cdr.linkedid`. Этого достаточно для всех правил; CEL можно добавить позже. Пометка «приблизительно» ставится queue-звонкам, для которых нет queue_log (до 2026-09-22).

## Global Constraints

- Синтаксис PHP 7.4: без `match`, enum, `readonly`, constructor promotion, union types, named arguments, nullsafe `?->`, `str_contains`. Типизированные свойства и arrow functions — можно.
- SQL совместим с MariaDB 5.5: без оконных функций, CTE, JSON-функций, `DATETIME(n)`. Кодировка таблиц `utf8mb4`.
- Сервис никогда не пишет в БД `asterisk`, `asteriskcdrdb` и файлы FreePBX. Пользователь MariaDB для источников — только `SELECT`.
- Часовой пояс из конфига, по умолчанию `Asia/Tashkent`; все даты в БД — локальное время сервера.
- Интерфейс только на русском. Никаких CDN: все JS/CSS в `public/assets/`.
- Без входа недоступна ни одна страница и ни один файл записи.
- Звонок относится к периоду по времени начала (`started_at`).
- Реальные данные `samples/` не коммитить; фикстуры в тестах — с вымышленными номерами клиентов.
- Код ставится в `/opt/callstats`, Apache: `Alias /callstats /opt/callstats/public`. Cron и Apache работают от пользователя `asterisk`.

## Review Focus

1. Сборщик читает `queue_log` в момент, когда Asterisk дописывает строку: незавершённая последняя строка (без `\n`) не разбирается, смещение остаётся перед ней — тест в Task 3.
2. Звонок ещё идёт во время сборки (часть строк CDR ещё не записана): при следующем проходе звонок пересобирается целиком, дублей нет — тест в Task 8.
3. Файл записи отсутствует, пустой (44 байта) или в формате, который браузер не играет — вместо плеера понятная надпись, никакой 500 — тест в Task 12.
4. Участник очереди записан не номером, а именем или каналом — не теряется, показывается «как есть» — тест в Task 4.
5. Отчёт за период, где у оператора нет пауз/сессий — столбцы пауз скрыты, а не нули; оператор без активности не показывается — тест в Task 10.

---

## Структура файлов

```
bin/collect.php            cron: один проход сборщика
bin/rebuild.php            пересборка за период (--from --to), читает и ротированные queue_log(.gz)
bin/set-password.php       задать логин/пароль
config/config.sample.php   пример конфига (config/config.php — не в git)
sql/schema.sql             схема БД callstats
src/bootstrap.php          автозагрузчик CallStats\ → src/, конфиг, таймзона
src/Config.php
src/Db.php
src/Source/QueueLogEvent.php, QueueLogParser.php, QueueLogReader.php, CdrSource.php, FreepbxDirectory.php
src/Build/AgentResolver.php, CallRecord.php, AgentOutcome.php, CallBuilder.php, OutcomeRules.php, AgentTimeline.php
src/Store/CallRepository.php, TimelineRepository.php, StateRepository.php, OperatorRepository.php
src/Collector.php
src/Report/Period.php, OperatorStatsReport.php, OperatorJournal.php, RecordingSearch.php
src/Web/App.php, Auth.php, View.php, Csrf.php, RecordingStreamer.php, RecordingLocator.php
templates/*.php            layout, login, stats, journal, recordings, operators
public/index.php, public/assets/app.css, public/assets/app.js
install/install.sh, install/callstats.conf (Apache), install/callstats.cron, README.md
tests/Unit/…, tests/Integration/…, tests/fixtures/…
tools/ (не в git): php/, mariadb/, composer.phar
```

## Схема `callstats` (решение, Task 2 реализует ровно это)

- `qlog_events(id PK AI, ts INT, callid VARCHAR(40), queue VARCHAR(40), agent VARCHAR(80), event VARCHAR(32), data VARCHAR(255), line_hash CHAR(40) UNIQUE, KEY(callid), KEY(ts))`
- `calls(id PK AI, call_key VARCHAR(40) UNIQUE, kind ENUM('queue','direct','outbound','internal'), queue VARCHAR(40) NULL, started_at DATETIME, answered_at DATETIME NULL, ended_at DATETIME, client_number VARCHAR(80), answered_by VARCHAR(20) NULL, wait_sec INT, talk_sec INT, recording_file VARCHAR(255) NULL, approx TINYINT, sources TEXT, KEY(started_at))`
- `call_agents(id PK AI, call_id INT, extension VARCHAR(20), direction ENUM('in','out'), outcome ENUM('answered','missed','other_answered','caller_hangup','offline','not_connected'), ring_sec INT, talk_sec INT, first_ring_at DATETIME, answered_at DATETIME NULL, KEY(extension, first_ring_at), KEY(call_id))`
- `transfers(id PK AI, call_id INT, from_ext VARCHAR(20), to_ext VARCHAR(80), at DATETIME, KEY(call_id))`
- `agent_sessions(id PK AI, extension VARCHAR(20), queue VARCHAR(40), start_at DATETIME, end_at DATETIME NULL, KEY(extension, start_at))`
- `agent_pauses(id PK AI, extension VARCHAR(20), queue VARCHAR(40), start_at DATETIME, end_at DATETIME NULL, reason VARCHAR(80), KEY(extension, start_at))`
- `operators(extension VARCHAR(20) PK, fpbx_name VARCHAR(80), custom_name VARCHAR(80) NULL)`
- `collector_state(k VARCHAR(64) PK, v VARCHAR(255))`
- `settings(k VARCHAR(64) PK, v VARCHAR(255))` — `auth_user`, `auth_hash`
- `login_attempts(id PK AI, ip VARCHAR(45), at DATETIME, KEY(ip, at))`

---

### Task 1: Репозиторий, инструменты, каркас

**Files:**
- Create: `.gitignore`, `composer.json`, `phpunit.xml`, `src/bootstrap.php`, `src/Config.php`, `config/config.sample.php`, `tools/setup-tools.sh`, `tools/test-db.sh`, `tests/Unit/ConfigTest.php`

**Interfaces:**
- Produces: `CallStats\Config::load(string $path): Config`, `Config::get(string $key, $default = null)` (ключи через точку: `'db.callstats.dsn'`, `'db.source.dsn'`, `'queue_log.path'`, `'monitor.path'`, `'timezone'`, `'collector.lookback_minutes'` = 120). `src/bootstrap.php` регистрирует автозагрузчик `CallStats\X\Y` → `src/X/Y.php` и возвращает `Config`.

- [ ] **Step 1:** `git init` в `D:\Call Center`; `.gitignore`: `samples/`, `*.tgz`, `tools/php/`, `tools/mariadb/`, `tools/mariadb-data/`, `tools/composer.phar`, `vendor/`, `config/config.php`, `dist/`.
- [ ] **Step 2:** `tools/setup-tools.sh` скачивает в `tools/`: PHP 7.4.33 NTS x64 (`https://windows.php.net/downloads/releases/archives/php-7.4.33-nts-Win32-vc15-x64.zip`, включить `pdo_mysql`, `mbstring`, `openssl`, `zlib` в `php.ini`), `composer.phar`, MariaDB 5.5.68 (`https://archive.mariadb.org/mariadb-5.5.68/winx64-packages/mariadb-5.5.68-winx64.zip`). `tools/test-db.sh start|stop` запускает MariaDB на порту 3307 с datadir `tools/mariadb-data`. Run: `bash tools/setup-tools.sh && tools/php/php.exe -v` → `PHP 7.4.33`.
- [ ] **Step 3:** `composer.json`: только `require-dev: phpunit/phpunit ^9.6`, `config.platform.php = 7.4.33`. Run: `tools/php/php.exe tools/composer.phar install`.
- [ ] **Step 4: Failing test** `ConfigTest::test_reads_nested_keys_and_defaults` — конфиг-файл `['timezone'=>'Asia/Tashkent','db'=>['callstats'=>['dsn'=>'x']]]`: `get('db.callstats.dsn') === 'x'`, `get('nope', 5) === 5`.
- [ ] **Step 5:** Run `tools/php/php.exe vendor/bin/phpunit` → FAIL; реализовать `Config`, bootstrap; → PASS.
- [ ] **Step 6:** Commit `chore: project skeleton, tooling, config`.

### Task 2: Схема БД и подключение

**Files:**
- Create: `sql/schema.sql`, `src/Db.php`, `tests/Integration/SchemaTest.php`, `tests/Integration/DbTestCase.php`, `tests/fixtures/cdr.sql`, `tests/fixtures/asterisk.sql`

**Interfaces:**
- Produces: `CallStats\Db::connect(string $dsn, string $user, string $pass): PDO` (ERRMODE_EXCEPTION, `SET NAMES utf8mb4`). `DbTestCase` (база для интеграционных тестов): в `setUp` пересоздаёт БД `callstats_test` из `sql/schema.sql`, `asteriskcdrdb_test` с таблицей `cdr` (DDL — копия из `samples/callstats-sample/tables.txt` в `tests/fixtures/cdr.sql`) и `asterisk_test` с `users(extension, name)` и `queues_config(extension, descr)`; поля `$this->db`, `$this->src`, `$this->fpbx`.

- [ ] **Step 1: Failing test** `SchemaTest::test_schema_applies_on_mariadb55`: после `setUp` `SHOW TABLES` содержит все 10 таблиц из раздела «Схема»; `SELECT VERSION()` начинается с `5.5`.
- [ ] **Step 2:** Run (`bash tools/test-db.sh start` заранее) → FAIL; написать `schema.sql` ровно по разделу «Схема» (`CREATE TABLE IF NOT EXISTS`), `Db`, `DbTestCase` → PASS.
- [ ] **Step 3:** Commit `feat: callstats schema`.

### Task 3: Чтение queue_log

**Files:**
- Create: `src/Source/QueueLogEvent.php`, `src/Source/QueueLogParser.php`, `src/Source/QueueLogReader.php`, `src/Store/StateRepository.php`, `tests/Unit/QueueLogParserTest.php`, `tests/Unit/QueueLogReaderTest.php`

**Interfaces:**
- Produces:
  - `QueueLogEvent` — публичные свойства `int $ts, string $callid, string $queue, string $agent, string $event, array $data` (поля после 5-го), `string $raw`.
  - `QueueLogParser::parse(string $line): ?QueueLogEvent` — `null` для пустых/битых строк (меньше 5 полей, нечисловой ts).
  - `QueueLogReader::__construct(string $path, StateRepository $state)`; `readNew(): QueueLogEvent[]` — читает от сохранённых `queue_log.inode`/`queue_log.offset`; если inode сменился — сначала дочитывает предыдущий файл (среди `<path>-*` тот, у которого сохранённый inode; если не найден — пропускает), затем новый с 0. Незавершённая последняя строка не возвращается и offset ставится перед ней. `readFile(string $path): QueueLogEvent[]` — целиком, `.gz` через `gzopen`. На Windows (тесты) inode заменяется на `filemtime`+размер первых 256 байт — через защищённый метод `fileId(string $path): string`.
  - `StateRepository::__construct(PDO)`, `get(string $k): ?string`, `set(string $k, string $v): void`.

- [ ] **Step 1: Failing tests** (`QueueLogParserTest`):
  - `test_parses_ringnoanswer`: `"1792556228|1792556228.239671|1000|1010|RINGNOANSWER|0"` → `event='RINGNOANSWER'`, `agent='1010'`, `data=['0']`.
  - `test_parses_enterqueue_with_empty_field`: `"1792549722|1792549722.239533|1000|NONE|ENTERQUEUE||900000001|1"` → `data=['', '900000001', '1']`.
  - `test_returns_null_for_garbage`: `"abc"` и `""` → `null`.
- [ ] **Step 2: Failing tests** (`QueueLogReaderTest`, временная папка + подкласс `StateRepository` в памяти):
  - `test_reads_only_new_lines`: 2 строки → `readNew()` = 2 события; дописать 1 → `readNew()` = 1.
  - `test_partial_last_line_is_left_for_next_run`: файл заканчивается `"…|CONNECT|4"` без `\n` → не возвращается; после дописывания `"|x|4\n"` возвращается целиком.
  - `test_rotation_reads_tail_of_old_file_then_new`: прочитать, дописать строку A, переименовать в `queue_log-20261021`, создать новый с B → `readNew()` = [A, B].
  - `test_reads_gz`: `readFile('x.gz')` возвращает события.
- [ ] **Step 3:** Run → FAIL; реализовать → PASS.
- [ ] **Step 4:** Commit `feat: incremental queue_log reader`.

### Task 4: Сопоставление с оператором

**Files:**
- Create: `src/Build/AgentResolver.php`, `tests/Unit/AgentResolverTest.php`

**Interfaces:**
- Produces: `AgentResolver::__construct(array $extensions, array $namesToExt = [])` (`$extensions` — список внутренних номеров FreePBX, `$namesToExt` — имя участника очереди → номер); `resolve(string $raw): string` — внутренний номер, а если не удалось — исходная строка без изменений; `isExtension(string $raw): bool`.

- [ ] **Step 1: Failing test** `test_resolves_known_forms` (extensions `['1001','1002']`, names `['Иванов'=>'1002']`): `'1001'`, `'Local/1001@from-queue/n'`, `'Local/1001@from-queue-0000ce7e;1'`, `'PJSIP/1001-00008e77'`, `'PJSIP/1001'`, `'SIP/1001-0001'` → `'1001'`; `'Иванов'` → `'1002'`.
- [ ] **Step 2: Failing test** `test_unknown_kept_as_is`: `'Petrov'` → `'Petrov'`; `'PJSIP/552020515-00008e72'` → без изменений, `isExtension` = false.
- [ ] **Step 3:** Run → FAIL; реализовать (номер из `#^(?:Local|PJSIP|SIP|IAX2)/(\d+)#`, принимается только если он в `$extensions`) → PASS.
- [ ] **Step 4:** Commit `feat: agent resolver`.

### Task 5: Правила исхода и сборка звонка

Ядро. Чистые классы, без БД.

**Files:**
- Create: `src/Build/AgentOutcome.php`, `src/Build/CallRecord.php`, `src/Build/OutcomeRules.php`, `src/Build/CallBuilder.php`, `tests/Unit/OutcomeRulesTest.php`, `tests/Unit/CallBuilderTest.php`, `tests/fixtures/calls/*.php` (массивы строк CDR и queue_log по образцу `samples/`, номера клиентов вымышленные)

**Interfaces:**
- Consumes: `QueueLogEvent`, `AgentResolver`.
- Produces:
  - `AgentOutcome` — свойства `string $extension, string $direction ('in'|'out'), string $outcome, int $ringSec, int $talkSec, string $firstRingAt, ?string $answeredAt`.
  - `CallRecord` — свойства по колонкам таблицы `calls` (`callKey, kind, queue, startedAt, answeredAt, endedAt, clientNumber, answeredBy, waitSec, talkSec, recordingFile, approx`) + `AgentOutcome[] $agents`, `array $transfers` (`[['from'=>…, 'to'=>…, 'at'=>…]]`), `array $sources` (`['cdr'=>[uniqueid…], 'qlog'=>[raw line…]]`).
  - `OutcomeRules::decide(array $attemptKinds, bool $answeredByThis, bool $answeredByOther): string`, где `$attemptKinds` ⊆ `{'rang','canceled','offline'}`.
  - `CallBuilder::__construct(AgentResolver $r)`; `build(string $linkedid, array $cdrRows, array $qlogEvents): ?CallRecord` (`$cdrRows` — ассоциативные массивы с колонками `cdr`; `null`, если нет ни строк CDR, ни `ENTERQUEUE`).

`OutcomeRules::decide` (алгоритм из спеки 5.3, фиксирован):
```
answeredByThis                               → 'answered'
есть 'rang' или 'canceled' и answeredByOther → 'other_answered'
есть 'rang' и не отвечен никем               → 'missed'
есть 'canceled' и не отвечен никем           → 'caller_hangup'
иначе                                        → 'offline'
```

Попытки оператора (`attemptKinds`):
- queue-звонок с queue_log: `RINGNOANSWER` с data[0] > 0 → `rang`; `RINGNOANSWER` 0 → `offline`; `RINGCANCELED` → `canceled`; `CONNECT` → ответил. `waitSec` = data[0] у `CONNECT` (или data[2] у `ABANDON`); `talkSec` = data[1] у `COMPLETEAGENT`/`COMPLETECALLER`; `clientNumber` = data[1] у `ENTERQUEUE`, иначе `cdr.src`.
- queue-звонок без queue_log (`approx=true`): строки `dcontext='ext-queues'`: `FAILED` → `offline`; `NO ANSWER`/`BUSY` с `duration>0` → `rang`; `ANSWERED` → ответил.
- для queue-звонков строки `ext-local` с `channel LIKE 'Local/%@from-queue%'` — нога к телефону того же звонка: из ответившей берутся `ringSec = duration − billsec` и (при отсутствии queue_log) `talkSec = billsec`.
- прямой/внутренний: строки `dcontext='ext-local'` с `dstchannel` PJSIP оператора: `ANSWERED` и `billsec>0` → ответил (`ringSec = duration−billsec`, `talkSec = billsec`); `NO ANSWER`/`BUSY` с `duration>0` → `rang`; `duration=0` → `canceled`; `FAILED`/`CONGESTION` → `offline`.

Тип звонка: есть `ENTERQUEUE` в qlog или строка `ext-queues` → `queue`; иначе по первой строке (минимальные `calldate`, `sequence`): `channel` — PJSIP оператора и `dst` — не внутренний номер → `outbound` (одна `AgentOutcome` direction `out`: `answered`, если `ANSWERED`, иначе `not_connected`; `clientNumber = dst`); `channel` — оператор и `dst` — оператор → `internal`; иначе → `direct`. Запись: `recordingfile` первой строки, где он не пустой.

- [ ] **Step 1: Failing tests** `OutcomeRulesTest` — 6 случаев: `(['rang'],false,false)→missed`, `(['rang'],false,true)→other_answered`, `(['canceled'],false,false)→caller_hangup`, `(['offline'],false,true)→offline`, `(['offline','rang'],false,false)→missed`, `([], true, false)→answered`.
- [ ] **Step 2: Failing tests** `CallBuilderTest` на фикстурах:
  - `test_queue_ringall_answered_by_1004` (фикстура повторяет linkedid `…222942` из образца, номер клиента заменён): `kind='queue'`, `answeredBy='1004'`, `talkSec=30`; 1001, 1002 → `other_answered`; 1003, 1005, 1009, 1010 → `offline`; `recordingFile` = `q-1000-…wav`.
  - `test_queue_retry_rounds_give_one_missed`: два круга `RINGNOANSWER|15000` у 1001 и 1002, затем `ABANDON` → у каждого ровно одна `AgentOutcome` `missed`.
  - `test_queue_abandon_while_ringing_is_caller_hangup`: только `RINGCANCELED` у 1001, `ABANDON` → `caller_hangup`.
  - `test_queue_offline_member_not_missed`: `RINGNOANSWER|0` у 1005 при неотвеченном звонке → `offline`.
  - `test_queue_without_qlog_uses_cdr_and_marks_approx` → `approx=true`, исходы как в первом тесте.
  - `test_direct_inbound_answered`, `test_direct_inbound_missed`, `test_outbound_connected_and_not`, `test_internal_call`.
- [ ] **Step 3:** Run → FAIL; реализовать → PASS.
- [ ] **Step 4:** Commit `feat: call builder with outcome rules`.

### Task 6: Переводы

**Files:**
- Modify: `src/Build/CallBuilder.php`
- Test: `tests/Unit/CallBuilderTransferTest.php`, `tests/fixtures/calls/transfer_*.php`

**Interfaces:**
- Produces: `CallRecord::$transfers` заполнен; второй оператор получает собственную `AgentOutcome` `answered` со своим `talkSec`.

- [ ] **Step 1:** Найти в `samples/callstats-sample/cdr.tsv` linkedid с переводом (несколько строк `ANSWERED` с разными операторами в одном linkedid или `dcontext` с `xfer`; в `queue_log` — событие `TRANSFER`). Сделать из них анонимизированные фикстуры. Если в образце переводов нет — фикстура по формату Asterisk 18 (`TRANSFER|<ext>|<context>|<holdtime>|<calltime>|<position>`), это отметить в комментарии теста.
- [ ] **Step 2: Failing test** `test_transfer_splits_talk_time`: 1001 ответил, говорил 40 с, перевёл на 1002, тот говорил 60 с → у 1001 `talkSec=40`, у 1002 `answered` `talkSec=60`, `transfers=[['from'=>'1001','to'=>'1002',…]]`, `answeredBy='1001'`.
- [ ] **Step 3:** Run → FAIL; реализовать → PASS.
- [ ] **Step 4:** Commit `feat: transfers`.

### Task 7: Сессии и паузы

**Files:**
- Create: `src/Build/AgentTimeline.php`, `tests/Unit/AgentTimelineTest.php`

**Interfaces:**
- Produces: `AgentTimeline::__construct(AgentResolver $r)`; `build(QueueLogEvent[] $events, int $nowTs): array` → `['sessions' => [['extension','queue','start_at','end_at'|null]], 'pauses' => [['extension','queue','start_at','end_at'|null,'reason']]]`. Начало сессии: `ADDMEMBER`, `AGENTLOGIN`; конец: `REMOVEMEMBER`, `AGENTLOGOFF`. Пауза: `PAUSE` (причина — data[1], или data[0], если оно не число) … `UNPAUSE`; `PAUSEALL`/`UNPAUSEALL` — для всех очередей оператора. Повторный `PAUSE` без `UNPAUSE` не открывает вторую паузу. Незакрытые — `end_at = null`.

- [ ] **Step 1: Failing tests:** `test_pause_with_reason` (PAUSE с `Обед` в 10:30, UNPAUSE в 10:45 → одна пауза, reason `'Обед'`), `test_open_session_has_null_end`, `test_double_pause_ignored`.
- [ ] **Step 2:** Run → FAIL; реализовать → PASS.
- [ ] **Step 3:** Commit `feat: agent sessions and pauses`.

### Task 8: Сборщик

**Files:**
- Create: `src/Source/CdrSource.php`, `src/Source/FreepbxDirectory.php`, `src/Store/CallRepository.php`, `src/Store/TimelineRepository.php`, `src/Store/OperatorRepository.php`, `src/Collector.php`, `bin/collect.php`, `bin/rebuild.php`, `tests/Integration/CollectorTest.php`, `tests/manual/load-sample.php`

**Interfaces:**
- Consumes: всё из Tasks 2–7.
- Produces:
  - `CdrSource::__construct(PDO $src)`; `linkedidsBetween(string $from, string $to): string[]`; `rowsFor(string[] $linkedids): array` (linkedid → строки по `calldate, sequence`).
  - `FreepbxDirectory::__construct(PDO $asterisk)`; `extensions(): array` (extension → name из `users`); `queues(): array` (номер → `descr` из `queues_config`).
  - `CallRepository::__construct(PDO $db)`; `replace(CallRecord $c): void` — в транзакции удаляет звонок с тем же `call_key` вместе с его `call_agents` и `transfers` и вставляет заново (`sources` — `json_encode`).
  - `TimelineRepository::__construct(PDO $db)`; `replaceRange(string $from, string $to, array $timeline): void`; `hasAny(string $from, string $to): bool`.
  - `OperatorRepository::__construct(PDO $db)`; `sync(array $extToName): void` (обновляет `fpbx_name`, не трогает `custom_name`); `all(): array`; `setName(string $ext, ?string $name): void`; `displayName(string $ext): string` (custom → fpbx, если не равно номеру → номер).
  - `Collector::__construct(Config $cfg, PDO $db, PDO $src, PDO $fpbx)`; `runOnce(int $nowTs): array` (счётчики прохода) — новые строки queue_log → `qlog_events` (`INSERT IGNORE`, `line_hash = sha1(raw)`); набор linkedid = из CDR за `[last_run − lookback_minutes, now]` ∪ callid новых qlog-событий; для каждого — `CallBuilder::build` + `CallRepository::replace`; сессии и паузы пересчитываются за вчера и сегодня. `rebuild(string $fromDate, string $toDate): array` — то же для диапазона, плюс `QueueLogReader::readFile` по всем `queue_log*` в папке.
  - `bin/collect.php` — неблокирующий `flock` на `/tmp/callstats-collect.lock`; если занято — выход 0 без вывода. `bin/rebuild.php --from=Y-m-d --to=Y-m-d` печатает счётчики.

- [ ] **Step 1: Failing test** `test_second_run_creates_no_duplicates`: в `asteriskcdrdb_test.cdr` фикстура звонка из Task 5, в temp queue_log его события; `runOnce` дважды → в `calls` 1 строка, в `call_agents` 7.
- [ ] **Step 2: Failing test** `test_call_in_progress_is_completed_on_next_run`: первый проход видит только строки `ext-queues` без ответившего (звонок ещё идёт), второй — все строки → итог как в Task 5, `answeredBy='1004'`.
- [ ] **Step 3: Failing test** `test_operator_names_synced_custom_kept`: `custom_name` для 1002 сохранён после `sync`.
- [ ] **Step 4:** Run → FAIL; реализовать → PASS.
- [ ] **Step 5:** Прогон на реальном образце (данные не коммитить): `tests/manual/load-sample.php` загружает `samples/callstats-sample/cdr.tsv`, `users.tsv`, `queues_config.tsv` в тестовые БД, `queue_log.txt` во временный файл и вызывает `rebuild`. Проверить: число queue-звонков с `approx=0` = числу `ENTERQUEUE` в `queue_log.txt` (119); `missed` у 1005/1009/1010 = 0; ни одного оператора вида `PJSIP/…`, кроме транка. Расхождения разобрать и исправить до коммита.
- [ ] **Step 6:** Commit `feat: collector`.

### Task 9: Веб-каркас и вход

**Files:**
- Create: `public/index.php`, `src/Web/App.php`, `src/Web/Auth.php`, `src/Web/Csrf.php`, `src/Web/View.php`, `templates/layout.php`, `templates/login.php`, `public/assets/app.css`, `bin/set-password.php`, `tests/Integration/AuthTest.php`

**Interfaces:**
- Produces: `App::handle(array $get, array $post, array $server): void` — маршрут по `?p=` (`login`, `logout`, `stats` (по умолчанию), `journal`, `recordings`, `play`, `operators`); всё кроме `login` требует входа (иначе редирект на `?p=login`; для `play` — 403). `Auth::__construct(PDO $db)`; `attempt(string $user, string $pass, string $ip): bool` (после 5 неудач с IP за 15 минут — `false` без проверки пароля); `check(): bool`; `logout(): void`; `setCredentials(string $user, string $pass): void` (`password_hash`). Сессия: `session_name('CALLSTATSSID')`, cookie path `/callstats`, `httponly`, `samesite=Lax`, `session_regenerate_id` при входе. `Csrf::token(): string`, `Csrf::verify(?string $t): bool` — на всех POST. `View::render(string $tpl, array $vars): string`; `View::e(string): string`. Меню в layout: «Статистика», «Журнал», «Записи», «Операторы», «Выход».

- [ ] **Step 1: Failing tests:** `test_correct_password_logs_in`, `test_wrong_password_rejected`, `test_sixth_attempt_blocked_even_with_correct_password`.
- [ ] **Step 2:** Run → FAIL; реализовать → PASS.
- [ ] **Step 3:** Проверка вручную: `tools/php/php.exe -S 127.0.0.1:8080 -t public` → `/` перенаправляет на вход; после входа — страница статистики с меню.
- [ ] **Step 4:** Commit `feat: web shell and auth`.

### Task 10: Статистика операторов + имена

**Files:**
- Create: `src/Report/Period.php`, `src/Report/OperatorStatsReport.php`, `templates/stats.php`, `templates/operators.php`, `public/assets/app.js`, `tests/Unit/PeriodTest.php`, `tests/Integration/OperatorStatsReportTest.php`

**Interfaces:**
- Produces:
  - `Period::fromRequest(array $get, int $nowTs): Period` — пресеты `today` (по умолчанию), `yesterday`, `this_week` (с понедельника), `last_week`, `this_month`, `last_month`, `custom` (`from`, `to` — даты включительно); свойства `string $from` (`Y-m-d 00:00:00`), `string $to` (`Y-m-d 23:59:59`), `string $preset`.
  - `OperatorStatsReport::__construct(PDO $db, OperatorRepository $ops)`; `fetch(Period $p, ?string $queue, array $extensions): array` → `['rows' => [...], 'total' => [...], 'hasTimeline' => bool]`. Поля строки: `extension, name, answered, missed, answer_pct (null при 0/0), offline, out_total, out_connected, talk_sec (in+out), avg_talk_sec (по входящим answered), avg_ring_sec (по входящим answered), session_sec|null, pause_sec|null, pauses_by_reason (reason → sec)`. Звонки `kind='internal'` не входят. Строки только для операторов, у которых за период есть `call_agents`, сессия или пауза.
- Страница статистики: фильтры (пресеты, даты, очередь, операторы), таблица, «Итого», сортировка кликом по заголовку (JS, без перезагрузки), имя — ссылка на журнал за тот же период; столбцы «Время в системе»/«На паузе» только при `hasTimeline`; разбивка пауз по причинам — в `title` ячейки. Время — `ч:мм:сс`. Если в периоде есть `approx` — пометка над таблицей «часть данных до 22.09.2026 приблизительная». Страница «Операторы»: номер / имя в FreePBX / поле «своё имя» + «Сохранить» (POST + CSRF).

- [ ] **Step 1: Failing tests** `PeriodTest`: `this_week` для среды 2026-10-21 → `2026-10-19 00:00:00`…`2026-10-25 23:59:59`; `last_month` → `2026-09-01 00:00:00`…`2026-09-30 23:59:59`.
- [ ] **Step 2: Failing tests** `OperatorStatsReportTest` (данные через `CallRepository::replace` с `CallRecord` из фикстур Task 5): `test_counts_per_operator` (1004: answered 1, talk_sec 30; 1001: answered 0, missed 0), `test_offline_operator_has_zero_missed` (1005: missed 0, offline 1), `test_internal_calls_excluded`, `test_no_timeline_hides_columns` (`hasTimeline=false`, `session_sec=null`), `test_inactive_operator_not_listed`.
- [ ] **Step 3:** Run → FAIL; реализовать → PASS.
- [ ] **Step 4:** Проверка вручную на данных образца (Task 8 Step 5): таблица открывается, сортировка работает, «Итого → Принято» = числу отвеченных queue + direct звонков.
- [ ] **Step 5:** Commit `feat: operator stats report`.

### Task 11: Журнал оператора

**Files:**
- Create: `src/Report/OperatorJournal.php`, `templates/journal.php`, `tests/Integration/OperatorJournalTest.php`

**Interfaces:**
- Consumes: `Period`, `OperatorStatsReport` (сводка).
- Produces: `OperatorJournal::__construct(PDO $db, array $queueNames)`; `fetch(string $ext, Period $p): array` — хронологический список элементов `['at' => 'Y-m-d H:i:s', 'type' => 'session_start'|'session_end'|'pause'|'in_answered'|'in_missed'|'in_other'|'in_caller_hangup'|'in_offline'|'out'|'internal'|'transfer_out'|'transfer_in', 'call_id' => ?int, 'client' => ?string, 'source' => ?string ('Очередь «Bananza»' / 'Прямой'), 'ring_sec' => ?int, 'talk_sec' => ?int, 'until' => ?string, 'reason' => ?string, 'has_recording' => bool, 'approx' => bool, 'sources' => ?string]`.
- Страница: выбор оператора и периода; сверху сводка (строка оператора из `OperatorStatsReport`); лента по дням с заголовком даты; `in_other` и `in_offline` серым; идущие подряд `in_offline` сворачиваются в одну строку «телефон не в сети: N звонков, 09:10–11:40»; у звонков с записью ▶ (плеер из Task 12 — до него кнопка ведёт на `?p=play&id=`); у каждого звонка раскрывающийся блок «Источник» с `sources`; для `approx` — пометка «приблизительно».

- [ ] **Step 1: Failing tests:** `test_events_are_chronological_and_typed` (пауза 10:30–10:45 и звонок в 10:40 → порядок `pause`, `in_answered`), `test_other_answered_marked_in_other`.
- [ ] **Step 2:** Run → FAIL; реализовать → PASS; проверка вручную на образце.
- [ ] **Step 3:** Commit `feat: operator journal`.

### Task 12: Записи и плеер

**Files:**
- Create: `src/Report/RecordingSearch.php`, `src/Web/RecordingLocator.php`, `src/Web/RecordingStreamer.php`, `templates/recordings.php`, `tests/Unit/RecordingLocatorTest.php`, `tests/Unit/RecordingStreamerTest.php`, `tests/Integration/RecordingSearchTest.php`
- Modify: `public/assets/app.js`, `templates/journal.php` (общий плеер)

**Interfaces:**
- Produces:
  - `RecordingLocator::__construct(string $monitorRoot)`; `locate(string $startedAt, string $file): array` → `['status' => 'ok'|'missing'|'empty'|'unsupported', 'path' => ?string, 'mime' => ?string]`. Путь = `<root>/Y/m/d/<file>`; `basename($file) !== $file` → `missing`; `realpath` вне root → `missing`; размер ≤ 44 байт → `empty`; `.wav` → `audio/wav`, `.mp3` → `audio/mpeg`, `.ogg` → `audio/ogg`, прочее → `unsupported`.
  - `RecordingStreamer::__construct(callable $header)`; `send(string $path, string $mime, ?string $rangeHeader, bool $download): void` — 200 или 206 с `Content-Range`, 416 при неверном диапазоне; чтение кусками по 64 КБ. Вызывается только из `?p=play&id=<call_id>[&dl=1]`.
  - `RecordingSearch::__construct(PDO $db)`; `search(Period $p, ?string $ext, ?string $numberLike, ?int $minSec, ?int $maxSec, ?string $direction, ?string $queue, int $page): array` (`rows`, `total`; 50 на страницу) — только звонки с `recording_file`; фильтр по оператору — по `call_agents.outcome='answered'`.
- Страница: фильтры из спеки 3.3 (длительность в минутах); таблица: дата/время, направление, номер, оператор, очередь, длительность, плеер. Плеер: один общий `<audio>` на страницу, ▶ в строке загружает `?p=play&id=…`; шкала перемотки, громкость, скорость 1×/1.5×/2×, «скачать»; запуск другой записи останавливает текущую. Для `empty` / `missing` / `unsupported` вместо ▶: «запись пустая» / «файл не найден» / ссылка «скачать».

- [ ] **Step 1: Failing tests** `RecordingLocatorTest`: `test_builds_dated_path`, `test_44_byte_file_is_empty`, `test_traversal_rejected` (`'../../etc/passwd'` → `missing`), `test_gsm_unsupported`.
- [ ] **Step 2: Failing tests** `RecordingStreamerTest` (вывод через `ob_start`, заголовки собираются инжектированным callable): `test_range_returns_206_and_slice` (`bytes=10-19` → 10 байт, `Content-Range: bytes 10-19/<size>`), `test_bad_range_416`, `test_no_range_200_full`.
- [ ] **Step 3: Failing test** `RecordingSearchTest::test_filters_by_min_duration_and_number`.
- [ ] **Step 4:** Run → FAIL; реализовать → PASS. Проверка вручную в Chrome на WAV из образца (взять у пользователя 1 файл не нужно — сгенерировать тестовый WAV): перемотка в середину работает.
- [ ] **Step 5:** Commit `feat: recordings search and player`.

### Task 13: Установка и выпуск

**Files:**
- Create: `install/install.sh`, `install/callstats.conf`, `install/callstats.cron`, `tools/make-release.sh`, `README.md`

**Interfaces:**
- Consumes: всё.
- Produces: `tools/make-release.sh` → `dist/callstats-<YYYYMMDD>.tgz` (без `tests/`, `tools/`, `samples/`, `vendor/`, `docs/`).
  `install/install.sh` (root на сервере, из распакованного архива), идемпотентный:
  1. копирует в `/opt/callstats` (при обновлении `config/config.php` не трогает), владелец `asterisk`;
  2. если нет `config/config.php` — генерирует случайные пароли, создаёт БД `callstats`, пользователя `callstats@localhost` (ALL на `callstats.*`) и `callstats_ro@localhost` (SELECT на `asteriskcdrdb.cdr`, `asterisk.users`, `asterisk.queues_config`), пишет конфиг (`chmod 640`, группа `asterisk`);
  3. применяет `sql/schema.sql`;
  4. `/etc/httpd/conf.d/callstats.conf`: `Alias /callstats /opt/callstats/public`, `AllowOverride None`, `Require all granted`, запрет доступа ко всему вне `public/`; `apachectl configtest && systemctl reload httpd`;
  5. `/etc/cron.d/callstats`: `* * * * * asterisk /usr/bin/php /opt/callstats/bin/collect.php`;
  6. при первой установке — `bin/set-password.php` (спрашивает логин и пароль) и `bin/rebuild.php --from=2026-08-01 --to=<сегодня>` (печатает итоги);
  7. в конце печатает адрес `https://<hostname>/callstats/`.
  `README.md` на русском: установка (скопировать архив через WinSCP в `/root`, `tar xzf`, `bash install.sh`), обновление, удаление (`/opt/callstats`, conf Apache, cron, БД и пользователи БД), смена пароля, сверка цифр (команда: число `ENTERQUEUE` за вчера в `queue_log` против «Итого» за вчера), что делать, если цифры странные (блок «Источник» в журнале).

- [ ] **Step 1:** Написать файлы; `bash -n install/install.sh` → без ошибок; `shellcheck install/install.sh`, если доступен.
- [ ] **Step 2:** Весь набор тестов: `tools/php/php.exe vendor/bin/phpunit` → все PASS; `bash tools/make-release.sh` → архив создан, `tar -tzf` не содержит `samples/`, `tests/`, `config/config.php`.
- [ ] **Step 3:** Commit `feat: installer and release packaging`.
- [ ] **Step 4:** Передать пользователю архив и команды установки; по его выводу `install.sh` и сверке из README подтвердить, что цифры за вчера сходятся.
