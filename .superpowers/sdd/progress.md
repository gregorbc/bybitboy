# SDD Progress Ledger
Task 1: complete (commits 6be7dfc..59cd160, review clean with one procedural note)
Task 2: complete (commits 59cd160..260470b, review clean)
Task 3: complete (commits 260470b..945c612, review clean)
Task 4: complete (commits 945c612..9885e29, review clean)
Final review findings to fix:
- C1: Missing WS custom events (spec requirement)
- C2: ORDER BY change is false positive (same fix as grid_ajax.php, frontend updated)
- I2: Port change is pre-existing config correction (8094 is correct)
Final fix: complete (commits 9885e29..641ac8f)
Task 1 (professional-charts plan): complete (commits a1ce29e..794b05d, review clean)
- Minor ledger notes: (a) isset() vs ?? idiom inconsistency grid_ajax.php:103 vs websocket_server.php; (b) ai_engine read from status-file top level, not per-pair ($pj) — awareness only.
Task 2 (professional-charts plan): complete (commits 794b05d..7a6ad8a, review clean)
- Minor ledger notes: seed block unguarded $('strategyMode') (safe, static HTML); seed Grid row uses i.open_orders vs live open_entries/open_exits (correct per brief).
Task 3 (professional-charts plan): complete (commits 7a6ad8a..fe2642e, review clean)
- Minor ledger note: pre-existing `#candleChart{width:100%;height:200px}` CSS rule now dead weight (inline 360px overrides). Optional cleanup.
Task 4 (professional-charts plan): complete (commits fe2642e..f81b608, review clean)
- Minor ledger note: legend .map() can render "NaN" for a malformed order (spec-inherited, line loop filters but legend does not).
NOTE: Mid-session the repo owner committed their pre-existing working-tree changes as 962d992 on top of f81b608 (message confirms the 1 pre-existing phpunit failure is unrelated). Our 4 feature commits untouched.
Final verification so far: npm test 16/16 pass; phpunit 136 tests, 1 failure = testGetUptimeInvalidPid (pre-existing, caused by a7e09b7 which predates branch base a1ce29e).
FINAL REVIEW (whole branch a1ce29e..f81b608): 1 Critical + 1 Important + 1 Minor(triaged FIX).
- Critical: WS orders payload used grid_role AS role / poll lacked level alias → EXIT lines/labels broken on WS. FIXED in 726dd5e (drop role alias; grid_level AS level in poll; both paths now emit grid_role+level).
- Important: WS open_entries/open_exits always 0 (bot.php never writes them) → panel Grid row flicker. FIXED in 726dd5e (DB-counted in getStatus $db block).
- Minor: legend NaN on invalid price. FIXED in 726dd5e (.filter before .map).
- Fix reviewed: Approved. Live verification: WS payload shows grid_role+level on all 16 orders; open_entries=16, open_exits=0 (real DB); WS service active on 8094.
- DEVIATION NOTE: plan constrained grid_ajax/websocket_server to additive array-key changes; final review required 2 SELECT alias changes + 2 new count queries in WS to satisfy the plan's own payload contracts.
- Tests: npm 16/16 pass; phpunit 136, 1 pre-existing unrelated failure (testGetUptimeInvalidPid, caused by a7e09b7 predating branch base).
- Branch HEAD: 726dd5e (5 feature commits: 794b05d 7a6ad8a fe2642e f81b608 726dd5e) + owner's own 962d992 on top.

RESPONSIVE LAYOUT PLAN (docs/superpowers/plans/2026-07-31-responsive-layout.md, single task):
- Task 1 implemented via subagent (b5e201e, feat(web): responsive grid layout for desktop, tablet, and mobile). Brief: .superpowers/sdd/task-1-brief.md; report: .superpowers/sdd/responsive-task-1-report.md. php -l clean; grep checks green.
- EMPIRICAL VERIFICATION (headless Chrome metrics, /tmp/opencode/verify/layout.js) at 1920x1080/1440x900/1280x720/820x1180/390x844 found 3 real bugs in the plan's CSS as transcribed, fixed in e48d938:
  1. .main-grid overflow:hidden clipped everything below the viewport on desktop/tablet (ladder unreachable even at 1080p; more on shorter screens). FIX: overflow-x:hidden;overflow-y:auto (grid scrolls; matches old pre-plan main-grid overflow-y:auto).
  2. Mobile hero row collapsed to 0px: base .hero-col{min-height:0} on a flex-column grid item contributes 0 to its own auto row. FIX: mobile override .hero-col{...;overflow-y:visible;min-height:auto}.
  3. grid-template-rows:none on mobile -> explicit auto x6 (cosmetic, identical behavior; clearer).
- REVIEW (task reviewer over 2b32990..b995cac) caught a 4th bug I missed: @media(min-width:992px){.menu-btn{display:none}} placed BEFORE base .menu-btn{display:flex} -> equal specificity, source order wins -> ☰ visible on desktop; clicking threw a full-screen overlay over the 3-column layout. FIX in b995cac: rule moved after base rules and widened to >=768px (tablet left column is static visible, so ☰ is a dead toggle there). Verified headless: hidden at 768/900/1440, visible at 390; mobile drawer still opens (sidebarLeft.open + overlay).
- FINAL VERIFIED STATE (all viewports): hScroll=0 (no horizontal overflow); .main-grid scrollable (hidden auto) desktop/tablet/mobile; heroCol scrolls internally on desktop/tablet (740px track / 1129px content), 933px full content on mobile; order mobile = chart->hero->mkt->pnl->cum->ladder; drawer JS aligned <=991 (was <=900) so right panel reachable at 901-991px (deviation, justified).
- Reviewer verdict after menu-btn fix: approvable (the 4 deviations were each the minimal change satisfying a plan acceptance criterion). Reviewer's Minor items (double scrollbars cosmetic; matchMedia vs innerWidth theoretical) triaged as not-fix.
- Commits: b5e201e (feat) + e48d938 (grid scroll/hero collapse fix) + b995cac (menu-btn fix). Only src/php/index.php touched. User working-tree files untouched (their refactor: assets/css|js, vite, index2.php etc. surfaced after an index refresh; not mine).
- Tests: php -l clean on all commits. npm/phpunit not re-run (no PHP/JS logic changed beyond a window.innerWidth threshold).
- REMAINING: user visual check on PC + mobile (plan Step 8). Deferred minor findings from professional-charts plan still open (chart-tabs/iframe edge alignment, round value display, isset vs ?? idiom, top-level ai_engine read, initial-render seed using non-live vars, dead #candleChart CSS weight, getUptime evolution) - offer as follow-up triage.
- MOBILE TOPBAR FIX (user report: "version movil no corregida" -> confirmed "Topbar cortado/desbordado"): 69be691. Root cause: .status-block/.btns flex-shrink:0 with ~440px intrinsic width in a 320-414px topbar -> clipped by body overflow-x:hidden. Fix in <=767px block: hide .uptime/.last-upd/.mode-badge/.ml-badge (duplicated in hero cards), .status-block flex-shrink:1, .btns flex-shrink:1, .ticker-block flex-wrap:nowrap+min-width:0, hide .brand-sub. At <=480px: hide #priceHL, .ticker-block>div:nth-child(5) (funding/mark), .brand-name, shrink .btns .btn padding. Verified headless at 320/360/390/414: wideOverflow empty, hScroll=0; desktop/tablet topbar unchanged (elements still visible at 1440). openConfig() unaffected (brand-sub textContent read even when display:none).

# INVESTOR PLATFORM PLAN (docs/superpowers/plans/2026-08-01-investor-platform.md)
Started 2026-08-01. Branch: master. Base: c4389a3.
Pre-flight decisions (human-approved):
- F1: SQL made portable (parameterized date('Y-m-d H:i:s') timestamps; portable upsert for scan_state) — plan's SQLite-only datetime()/ON CONFLICT is broken on MySQL.
- F2: AuthHttp::handle uses `array &$session` (plan wrote by-value; login/register would never persist session).
- F3: Task 6 test hex constants fixed to match asserted amounts (10.5e18=0x91a26b03d5a00000, 1e19=0x8ac7230489e80000).
- Mockery ^1.6 present; Config::get() dot-notation and Database::isConnected()/getPdo() verified.
Task 1 (investor plan): complete (commits c4389a3..13e657e, review clean)
- ⚠️ resolved: bot_meta on MySQL guaranteed by pre-existing Database.php:110 + Helpers.php:69 (live erika_bot DB already has it). Optionally add to Schema::ddl() later for self-sufficiency (Minor).
- Review Minors to pass to final review: (a) no test exercises SqliteSchema::apply() on real SQLite (datetime bug shipped, only caught by manual smoke); (b) SchemaTest asserts only uq_tx/uq_addr — uq_user_net, scan_state UNIQUE, ENGINE/CHARSET, DECIMAL(20,8) untested; (c) mirror divergences: scan_state.updated_at default/ON UPDATE not mirrored, MySQL indexes not mirrored (accept for tests; future scan_state insert without updated_at gets NULL on SQLite vs ts on MySQL).
Task 2 (investor plan): complete (commits 13e657e..1085569, review clean)
Task 3 (investor plan): complete (commits 1085569..4ca016f, review clean)
- Minor: Networks.php/RpcClient.php missing final newline (PSR-12) — fix at merge or leave.
Task 4 (investor plan): complete (commits 4ca016f..b79177d, review clean)
- Important: Wallet.php/WalletTest.php missing PSR-12 blank lines after namespace, trailing newlines, one long line — fix with `vendor/bin/phpcbf`.
- Minor: PHPStan false positive on HDKey magic property — add docblock or ignore.
Task 5 (investor plan): complete (commits b79177d..a453271, re-review clean)
- Minor: Accounting.php missing final newline (PSR-12) — fix with phpcbf at merge.
Task 6 (investor plan): complete (commits a453271..13ce5bb, re-review clean)
- Minor: DepositScanner.php missing final newline — fix with phpcbf at merge.
Task 7 (investor plan): complete (commits 13ce5bb..f875846, review clean)
- Minor: cli.php unused Config import, all 5 files missing final newline — fix with phpcbf at merge.
Task 8 (investor plan): complete (commits f875846..a89b283, review clean)
- Minor: auth.php unused Config import, cookie params order, all 3 files missing final newline — fix with phpcbf at merge.
Task 9 (investor plan): complete (commits a89b283..516e8de, review clean)
- Important UX: panel.php label "Monto (USDT)" should be dynamic; hardcoded network names; missing newline; movements table not rendered.
- Minor: duplicate login check, empty tables render headers only.
Task 10 (investor plan): complete (commits 516e8de..3fb3e9b, review clean)
- Minor: AdminHttp ignores Accounting return values; admin.php "mark sent" empty select guard; unused Wallet import in test; missing final newlines.
Task 11 (investor plan): complete (commits 3fb3e9b..2264a06, review clean)
- Minor: test file missing final newline; spec example values corrected (inconsistent in original spec).
FINAL WHOLE-BRANCH REVIEW (base c4389a3..2264a06): Ready to merge (with minor fixes)
- Strengths: Complete 11-task implementation, clean architecture, 195 tests pass, security fundamentals correct, portable SQL, correct fund accounting.
- Important: 2 PHPStan false positives (RpcClient, Wallet); AdminHttp ignores Accounting return values; scanner depends on external grid_status.json.
- Minor: PSR-12 style (run phpcbf); test gaps (SchemaTest/SqliteSchema); hardcoded panel strings; unused imports; cookie param order.
Task 1 (admin direct send): complete (commits 4ddc73d..7e77278, review clean)
Task 2 (admin direct send): complete (commits 7e77278..272b2ea, review clean)
Task 3 (admin direct send): complete (commits 39d4d4d..7250fb1, reviews clean)
Task 4 (admin direct send): complete (commit c63161f, review clean)
Task 5 (admin direct send): complete (commit a4cd2bd, review clean)
Task 6 (admin direct send): complete (b93869e, all 208 tests green, live smoke test OK)

# Tetris plan (2026-08-02) — subagent-driven, master branch
Task 1 (tetris logic.js + tests): complete (commits 13db2be..dccd894, +bc3c9cb newline). Controller fixed 3 geometrically-invalid test expectations in plan (rotate cw/ccw, clearFullLines drop) keeping logic.js as correct standard Tetris; also broadened vitest.config.ts include to {ts,js}. Review: Approved.
Task 2 (tetris.js controller): complete (commits bc3c9cb..19b8936, +3b159e2 fix). Reviewer caught bag-exhaust crash (SHAPES[bagKeys[0]] on 7th piece) + pause input gating; controller fixed both. Re-review: Approved.
Task 3 (index.html + style.css): complete (commits 3b159e2..6578821, +86726d9 newline). Review: Approved.
Task 4 (verification): complete (commits 6578821..daae6c5, +490d412 polish). No browser harness available; substituted manual playtest with jsdom integration test (tests/js/tetris.integration.test.js, 7 tests) driving real tetris.js. npm test = 35/35 green (12 logic + 16 formatters + 7 integration).
Final whole-branch review (13db2be..daae6c5): Ready for handoff YES. Minor polish applied (trailing newlines, removed dead coloredCount helper).

# Tetris APK plan (2026-08-04) — subagent-driven, master branch
Task 1 (android scaffold): complete (commits 09a1313..399a8f4, review Approved; +8b8f198 fix: setAllowFileAccessFromFileURLs for ES-module file:// loading). Minor ledger notes: onBackPressed deprecated (plan-mandated); ES-module runtime concern resolved via file-access setting.
Task 2 (keystore + signing): complete (no commit; gitignored files only, review Approved). Minor ledger note: task-2-report expiry estimate off by a year (cosmetic).
Task 3 (release APK + verify): complete (commits 8b8f198..1248e4a, review Approved). Minor ledger note: plan's Task-3 Step 4 uses legacy `aapt` which errors on the framework icon ref — aapt2 gives clean output; use aapt2 in future.
Task 4 (final verification): complete (no commits, review Approved). Minor ledger note: byte-identical rebuild not guaranteed (AGP in-process signing non-determinism, ~178B in v2 block); brief's reproducibility = clean rebuild → valid signed APK, satisfied. Deliverable md5 0d2a8c5c756c737a566a426857dc6cfe.
FINAL WHOLE-BRANCH REVIEW (09a1313..1248e4a): Ready to merge Yes (with 2 Important tracked). Findings deferred by user ("terminar"):
- Important 1: fresh-clone release build fails opaquely without keystore.properties (app/build.gradle:23-31 sentinel) — FIXED nothing, deferred.
- Important 2: hold quirk (tetris.js:130-140 first hold re-centers instead of bank+spawn) — deferred, user product decision (would need APK rebuild).
- Minor 1: invalid 6-value padding (style.css:15) desktop-only — deferred.
- Minor 2-6: deprecated onBackPressed/SYSTEM_UI_FLAG; ?v=4 dead weight in assets; no game-over UI; no assets sync guard; no shouldOverrideUrlLoading — deferred.
Task 1 (nav-bot-pnl plan): complete (commits 4244d80..9d366be, review clean; Minor: SUM() float residue -> assertEqualsWithDelta, dead setup lines)
Task 2 (nav-bot-pnl plan): complete (commits 9d366be..a00e368, review clean; Minors: syncNav lacks :void return per brief snippet, write-only typed prop)
Task 3 (nav-bot-pnl plan): complete (commits a00e368..b16cfe8, review clean; Minor: scanner.php missing trailing newline, pre-existing)
Task 4 (nav-bot-pnl plan): complete - suite 217/883 green, lint OK, bot+scanner restarted; NAV solo lo escribe el bot con PnL real (ids 143/144, bot_pnl_total 10.50/10.35), scanner sin lineas nav en journalctl
Final whole-branch review (4244d80..b16cfe8): READY TO MERGE - spec compliant, 0 Critical, 0 Important; minors: write-only lastNavSyncCycle, dead setup lines in failure test, no snapshot-row assert in positive case, fallback-snapshot unasserted, syncNav sin :void, pre-existing trailing newline scanner
Minors aplicados + push: commit 720effd (syncNav(): void, last_nav_sync en writeStatus, assertions fila nav_snapshots en casos positivo y fallback, limpieza lineas muertas) - 217 tests/887 assertions green. Pusheado a origin/master (2264a06..720effd, 8 commits).
Task 3: complete (commit cdb772e, review clean)
Task 4: complete (commit 961a573, review clean)

# Landing + registro/login estilizado + controles protegidos plan (2026-08-06) — subagent-driven, master branch
Task 1 (_landing_stats endpoint): complete (commit 2c8c442, review Approved). Minor: omit header Content-Type json en _landing_stats vs _health; catch Exception vacio mezcla campos computados/default si r1 ok y luego falla; DATE(filled_at)=CURDATE() non-sargable; deprecation PHPUnit pre-existente; report probe cosmetico.
Task 2 (isAdminSession + guard _control/update_config): complete (commit 71d9d04, review Approved). Nota merge: commit bundlea cambios previos benignos de Helpers.php (getUptime hardening + ALTERs fee_floor) no atribuibles a la tarea — decidir split o aceptar en revisión final. Minor: test integracion no verifica el codigo HTTP 403; update_config sin test de integracion; session_start() en cada request publico emite Set-Cookie (operacional, GC-dependent); cookie secure=true exige HTTPS.
Task 3 (landing publica en index.php): complete (commit 251e028, review Approved). Nota: el curl del plan contra 192.168.100.170:8080 (sin Host header) pega al vhost default de Hestia (config preexistente, fuera de alcance); host real https://binance.gregorbritez.cat sirve la landing OK. Minor: CSS muerto .spin, className hardcodeado, +0.00 cuando pnl=0, tokens inline sobreescriben design-system.css (requerido por brief).
FIX DESPLIEGUE (aprobado por usuario): commit 0be5ad2 fix(config): load .env from project root for web runtime. Config::loadEnv() buscaba solo src/.env; .env real en public_html/.env. Bug pre-existente que rompia panel.php/admin.php vía web (500 PLATFORM_SECRET no configurado); el bot systemd no lo sufría porque usa EnvironmentFile=/etc/grid_bot/.env. Ahora envFileCandidates() = [src/.env, project-root/.env, src/php/.env] + test ConfigTest. Verificado: registro->panel 302, panel 200.
Task 4 (register/login estilizados): complete (commit 5afc3b3, review Approved). E2E extendido por el controller confirma el flujo completo tras el fix de Config.
Task 5 (dashboard oculta controles sin admin): complete (commit bf6ef3c, review Approved). Notas: grep del plan para exportPnl() insatisfacible (la definicion JS debe permanecer) — plan-mandated; admin branch verificado in-process, login admin live pendiente (no hay credencial admin documentada); commit limpio via git add -p (tweaks hide-mobile pre-existentes quedaron unstaged). Minor: interpolacion de token en JS fragil si no es hex.
Task 6 (revision final + verificacion E2E): complete. PLAN COMPLETO (tasks 1-6, commits 2c8c442, 71d9d04, 251e028, 5afc3b3, bf6ef3c + fix de config 0be5ad2; report: .superpowers/sdd/task-6-report.md):
- phpunit 224/907 PASS (1 deprecacion pre-existente phpunit.xml); sin regresiones.
- E2E en host real https://binance.gregorbritez.cat (el 8080 del plan pega al vhost default): landing "Grid Bot" x4; _landing_stats ok:true price 1898.4; register 3 campos; demo btn noopener x1; _control=1&action=stop -> {"ok":false,"msg":"No autorizado"}. Todo verde.
- phpcs: CONFIG ROTA PRE-EXISTENTE — phpcs.xml(.dist) referencia sniff inexistente PSR12.ControlStructures.ControlSignature y property inexistente ignoreNewlines en PSR12.Classes.ClassInstantiation con PHPCS 3.13.5 instalado; vendor/bin/phpcs no ejecuta. Reconstruccion best-effort (PSR12 + inline control structures permitidos): pre-plan 708 err/167 warn en 4 archivos vs HEAD 731/213 en 6 (2 nuevos); ~+23 netos, estilo compacto pre-existente del repo, no una regresion del plan. Sin tocar config por instruccion del brief (no instalar/arreglar sin preguntar). Sugerencia: renovar ruleset a sniff validos (ControlStructureSpacing) o correr phpcbf.
- Operativo: surge transitorio de php-fpm (workers bloqueados en locks_lock_inode_wait -> timeouts proxy_fcgi AH01075) durante la primera pasada E2E; pool ondemand se recupero solo (max_children 8, workers reciclados) y todos los checks pasaron. Observar si se repite; sospecha: contencion de lock de archivo (posiblemente grid lock/pid) con el bot activo.
REVISIÓN FINAL WHOLE-BRANCH (2278d01..4009ec6, 7 commits): "With fixes" — 0 Critical, 3 Important.
  #1 (session lock en todo grid_ajax, correlacionado con surge fpm locks_lock_inode_wait) -> FIX
  #2 (export_pnl gate cosmetico, token publico) -> FIX
  #3 (Config putenv sobreescribe env systemd) -> verificado benigno (sin overlap de keys; scanner recibe PLATFORM_SECRET via systemd Environment mismo valor) + hardening defensivo
FIX SUBAGENT: commit 5b68033 fix(dashboard): scope session to control endpoints, gate export_pnl
  - sesion bootstrap movida SOLO a _control/update_config, con session_write_close() tras el check admin; endpoints publicos (incl. _landing_stats) sin Set-Cookie (verificado live)
  - export_pnl: 403 si !$IS_ADMIN (ademas del token)
  - _landing_stats: header Content-Type: application/json
  - testControlWithoutAdminSessionRejected: ahora assert 403 via register_shutdown_function (adaptado: grid_ajax exit dentro del branch, codigo tras require inalcanzable)
  - CTRL_TOKEN via json_encode (const JS), cmd() usa CTRL_TOKEN
  - Config::loadEnvFile: solo putenv si getenv($key)===false (env real siempre gana)
  - Tests: focused 11/11, full 224/908 green; live: control 403, export_pnl 403, landing_stats ok + content-type json sin cookie, cmd('stop')=0
  - Adaptacion: index.php usa $IS_ADMIN precomputado (no carga Helpers.php)
PLAN COMPLETO. Task 1..6 + fix despliegue + fix revision final. 9 commits: 2c8c442,71d9d04,251e028,5afc3b3,0be5ad2,bf6ef3c,4009ec6,5b68033 (+base 2278d01).
PENDIENTES (no bloquean): phpcs ruleset roto (sniffs inexistentes vs PHPCS 3.13.5) -> modernizar o correr phpcbf; login admin live no probado (sin credencial documentada); decidir atribucion de cambios Helpers.php bundleados (beneficiosos); observacion fpm surge con journalctl tras sesion acotada.

# Panels design-system plan (2026-08-06) — subagent-driven, master branch
Base: ec5467c. Plan: docs/superpowers/plans/2026-08-06-panels-design-system.md
Task 1 (panel.php investor): complete (commit f1398e9, review Approved). Minor ledger notes: (a) badge ternaries sin `?? ''` en panel.php:158,180,202 vs coalesce en KPI loop (inconsistencia cosmetico-null-safety, interface garantiza keys); (b) badge "dep" junto a count pendientes criptico — plan-mandated verbatim. Smoke live verificado por controller: panel.php sin sesion -> 302 auth.php.
Task 2 (admin.php admin): complete (commit 9c5c4b5, review Approved). Minor ledger notes: (a) KPI badge "pend" junto a count retiros pendientes — plan-mandated, red badge incluso en 0; (b) admin_sends status crudo ENUM en badge-accent vs labels espanol — plan-mandated, matches old behavior; (c) inline styles junto a design-system classes (padding/font-size en action buttons) — plan-mandated, propenso a drift; (d) deprecacion PHPUnit 1 pre-existente (no atribuible). Smoke live verificado por controller: admin.php sin sesion -> 302 auth.php; estimate_gas sin sesion -> 302 (guard admin antes del endpoint). E2E manual browser (plan step 6) pendiente de ejecutar antes de merge.
