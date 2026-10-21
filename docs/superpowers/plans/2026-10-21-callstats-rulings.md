# CallStats — решения, принятые при разработке

Перенесено из журнала выполнения плана (2026-10-21/22). Каждое: что решено — почему — цена ошибки.

- add table `queues(queue VARCHAR(40) PK, name VARCHAR(80))` to schema (T2, test expects 11 tables); Collector (T8) syncs it from FreepbxDirectory::queues(); OperatorJournal (T11) and stats/recordings filters read queue names from it, constructor becomes `OperatorJournal(PDO $db)` — why: Global Constraint "web reads only callstats" + spec anti-goal on touching FreePBX — cost if wrong: one extra table, trivial.
- T1 appends to existing .gitignore rather than creating — why: controller created it to keep samples out of first commit — cost: none.
- repo uses LF line endings (.gitattributes, core.autocrlf=false) — why: install.sh runs on Linux — cost: none.
- T3 fileId() Windows fallback = sha1 of first 256 bytes only (plan said filemtime+first 256 bytes) — why: mtime changes on every append, would look like rotation — cost if wrong: test-only path, Linux uses inode.
- readNew() commits offset before caller stores events; Task 8 Collector must save state values before readNew and restore them if storing qlog events fails — why: otherwise a DB error loses events forever — cost if wrong: a few lines in Collector.
- queue attempts — RINGNOANSWER with ringtime <= 1000 ms → 'offline' (sample: only offline phones 1005/1009/1010 log 1000); RINGCANCELED → 'offline' when the operator's CDR ext-queues legs for this call exist and are all FAILED, else 'canceled' — why: spec anti-goal "inflated misses" + spec 5.3 offline rule; online phones 1001–1004 also log short RINGCANCELED so a pure threshold would mislabel them — cost if wrong: a few attempts mislabelled offline vs other_answered, never counted as missed either way.
- approx (no queue_log) queue calls — ext-queues NO ANSWER/BUSY leg with duration < ring timeout (CallBuilder ctor param int $ringTimeoutSec = 15, from queues_details timeout) → 'canceled', duration >= timeout → 'rang' — why: avoids 28/119 caller-hangups becoming misses in pre-22.09 data — cost if wrong: approx data only, already flagged.
- Follow Me Local/FMPR-/FMGL- channels not present in sample (0 rows) — no resolver change now; verify on server data after install — cost if wrong: direct calls via Follow Me unattributed, visible as "as is" operators.
- keep — not counted as missed either way; journal label only — cost if wrong: misleading grey line in journal.
- direct inbound legs (ext-local, not from-queue) NO ANSWER/BUSY with duration < direct ring timeout → 'canceled' (caller hung up), >= → 'rang'; new ctor param int $directRingTimeoutSec = 20 (FreePBX Follow Me grptime 20 on this server) — why: spec 5.3 caller-hangup is not a miss — cost if wrong: direct short-ring misses undercounted.
- direct ring timeout 20 s also applies to internal calls (buildDirect serves both) — why: internal calls are excluded from stats anyway; journal shows caller_hangup — cost if wrong: journal label only.
- T7 — PAUSEALL opens pause for each known queue of the operator (else queue 'NONE'); REMOVEMEMBER closes open pause; events with ts > nowTs ignored; open intervals stay end_at=null — why: plan silent, sensible defaults — cost if wrong: pause totals per queue slightly off.
- carry to Task 10 — session_sec and pause_sec = UNION of intervals per operator (sessions of several queues + NONE overlap), open intervals capped at min(now, period end), clipped to period — why: avoid double-count of «Время в системе» — cost if wrong: inflated time.
- T8 — config adds collector.queue_ring_timeout=15, collector.direct_ring_timeout=20; collector re-checks calls with ended_at >= now-lookback; timeline recompute starts at earliest open session — why: plan underspecified — cost if wrong: minor recompute overhead.
- long calls — collector selects calldate >= now-24h (collector.max_call_hours) AND calldate+duration >= last_run-lookback — why: CDR written at hangup — cost if wrong: calls >24h missed until rebuild.
- drop caller_hangup outcomes with ringSec=0 (never rang) — why: noise on 7 members for <1s calls — cost if wrong: none (not counted anyway).
- qlog_events gets raw VARCHAR(512) column — why: exact source lines/re-hydration — cost: none, not deployed.
- T9 — logout via POST+CSRF; route map extensible per page; security headers; cookie secure on HTTPS; purge login_attempts > 1 day — why: plan silent, security defaults — cost if wrong: none.
- T9 hardening added to fix round — strict session mode + CSRF reset on login, session key callstats_auth, 12h absolute / 2h idle session lifetime, lazy Csrf, stty restore — why: app guards call recordings; cheap — cost if wrong: user re-logs in more often.
- T10 — queue filter excludes outbound; operator filter multi-select; PRG on operators page; names trimmed ≤80 — why: plan silent — cost if wrong: UI tweak.
- internal calls in journal show the other operator (ext + name) instead of client_number — why: client_number may be viewer's own number — cost: none.
- T13 — RedirectMatch /callstats → /callstats/; cron output appended to /var/log/callstats.log (+logrotate); uninstall.sh with --drop-db; upgrade via --rebuild flag; config written via PHP var_export — why: plan silent / deployment safety — cost if wrong: install tweak.
- code owned root:root, config root:asterisk 640 (brief said owner asterisk) — why: Apache/cron need read only; safer — cost: none.
- add post-reload smoke check (HTTP 200/302, PHP executed under Alias) + print http and https URLs — why: unknown PHP handler/HTTPS on target — cost: none.
- I5 — replace number in working tree (test + plan doc), no git history rewrite — why: no remote, never shared; rewrite is owner's call — cost if wrong: number remains in local history.
- final wave also takes M1 (neutral approx text), M2 (extension columns VARCHAR(80)), M4 (sort ids before CDR batching), M7 (README recording check) — why: cheap, avoid surprises on server — cost: none.

## Отложенные мелочи

- Task 1: minor (deferred): Config::load/get lack scalar/return type declarations
- Task 1: minor (deferred): no test that src/bootstrap.php returns Config and sets timezone
- Task 1: minor (deferred): composer fetched from latest-2.2.x without checksum (dev only)
- Task 2: minor (deferred): schema tables lack COLLATE=utf8mb4_unicode_ci (default general_ci vs cdr unicode_ci) — matters only for cross-DB string compares
- Task 2: minor (deferred): DbTestCase unreachable return after markTestSkipped; dsn+dbname concat breaks if env DSN has dbname
- Task 3: minor (deferred): RED step skipped by implementer (tests written with code)
- Task 3: minor (deferred): Windows fileId '' for empty/partial first line → spurious "rotation" (harmless)
- Task 3: minor (deferred): glob() on path with metachars; ?PDO test-double hack in StateRepository
- Task 3: minor (deferred): no tests for rotated .gz skip, first-run partial line, old file not found
- Task 4: minor (deferred): missing edge tests (IAX2, int extensions, empty string); name mapped to unknown ext resolves to that value
- Task 5: minor (deferred): 12 attempts of offline phones labelled caller_hangup instead of offline (caller hung up at round start, CDR leg NO ANSWER dur 0) — Ruling: keep — not counted as missed either way; journal label only — cost if wrong: misleading grey line in journal.
- Task 5: minor (deferred): approx talk=billsec of ext-queues row includes ring when no phone leg; outbound detection treats feature codes (*97) as outbound; queue timeout < member ring → never missed (undercount)
- Task 6: minor (deferred): pre-answer foreign phone legs (call-forward) dropped for approx calls
- Task 6: minor (deferred): attended consultation billsec added to target talk → call talkSec double-counts consultation (test locks 69/119)
- Task 6: minor (deferred): externalRows doesn't require ANSWERED/trunk dstchannel — feature-code rows could look like external transfers
- Task 6: minor (deferred): missing tests: transfer back, unanswered target, qlog-only transfer w/o CDR leg (target gets no outcome)
- Task 7: minor (deferred): future-event test doesn't prove ts>nowTs ignore; untested AGENTLOGIN/LOGOFF, UNPAUSE NONE, same-ts order; tz set in setUp not restored; queue UNPAUSE doesn't close PAUSEALL 'NONE' fallback pause
- Task 8: minor (deferred): runOnce leaves stale row when build() returns null for stored call; new raw column only via CREATE IF NOT EXISTS (fine pre-deploy); exit() in catch skips finally
- Task 8: minor (deferred): ProcessLock file_exists/fopen race; chmod unverified on Windows
- Task 9: minor (deferred): App routing has no automated test (manual curl only)
- Task 10: minor (deferred): hasTimeline ignores queue/operator filters
- Task 10: minor (deferred): JS click-sorting not run in a browser — check in final review/manual
- Task 11: minor (deferred): session spanning whole period shows no login line; untested pause without end_at and session boundary cases
- Task 11: minor (deferred): N+1 queries for internal calls' other party; journal summary row lacks pause tooltip
- Task 12: minor (deferred): journal ▶ not status-checked; symlinked date dirs → missing (document); "до N мин" = N*60+59; query for id 0
- Task 13: minor (deferred): update deletes old code before copying new (no staging swap); uninstall with password-protected MariaDB root silently leaves users; PDO multi-statement schema error only for first statement
- Task 13: minor (deferred): pipefail/grep SIGPIPE in smoke check; 302 not proving PHP; PDO auth check w/o ERRMODE; CURDATE tz vs OS tz
