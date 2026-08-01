# Diseño: Plataforma de inversión — login, depósitos EVM y contabilidad

Fecha: 2026-08-01
Ámbito: `src/php` (app web PHP monolítica + daemon escáner) y MySQL

## Problema

El dashboard actual es privado del operador (auth global por `EXPORT_TOKEN`). No existe forma de que inversores externos accedan, depositen USDT/USDC y participen del PnL del bot.

## Objetivo

Plataforma donde un inversor se registra, recibe una dirección de depósito USDT/USDC por red EVM (Ethereum, BSC y otras EVM configurables), deposita y su equidad crece con el PnL del bot (pool único, reparto proporcional). Puede solicitar retiros que el admin aprueba y envía manualmente.

Decisiones de producto (validadas con el operador):
- Modelo: **fondo manejado por el bot**; pool único, PnL proporcional por unidades (NAV).
- Alta de usuarios: **auto-registro abierto** (usuario + email + contraseña), sin captcha.
- Depósitos: **wallet propia + escáner blockchain** (HD wallet BIP-44, monitoreo RPC público/explorer).
- Redes: **Ethereum (ERC20), BSC (BEP20) y otras EVM** (arquitectura por configuración).
- Retiros: solicitud del inversor + **aprobación del admin**; el envío de fondos es **manual** (el admin firma desde su wallet y registra el tx_hash).
- Sin captcha en esta fase.

## 1. Arquitectura

Todo en `src/php`, patrón de proyecto (composer + PHP 8.3 + MySQL + systemd):

| Componente | Qué hace | Archivo |
|---|---|---|
| Auth | Registro/login/logout, sesiones, CSRF, rate-limit, roles | `auth.php`, `Core/Auth.php` |
| Panel inversor | Saldo, dirección por red, depositar, movimientos, retiros | `panel.php`, `Core/Investment.php` |
| Panel admin | Usuarios, depósitos, retiros, estado del fondo | `admin.php` |
| Wallet HD | Derivación determinista BIP-44, cifrado AES-256-GCM | `Core/Wallet.php` |
| Escáner chain | Daemon systemd; detecta Transfer ERC20/BEP20, confirma y acredita; recalcula NAV | `scanner.php` |
| Contabilidad | NAV/unidades, emisión/quema, libro mayor | `Core/Accounting.php` |
| MySQL | Tablas del sistema | — |

Flujo: depósito → dirección propia (wallet del operador) → escáner detecta en chain → acredita (unidades = monto/NAV) → NAV recalcula cada ciclo → panel muestra equidad → retiro solicitado → admin aprueba/ envía → quema unidades.

## 2. Modelo de datos (MySQL)

- `users` — id, username (único), email (único), password_hash, role (`admin`|`investor`), status (`active`|`suspended`), created_at, last_login_at.
- `deposit_addresses` — id, user_id, network, address (única por network), derivation_index, status, created_at.
- `deposits` — id, user_id, network, tx_hash (UNIQUE), amount, token (`USDT`|`USDC`), confirmations, status (`pending`|`credited`|`failed`), deployed (bool, default false: el admin lo marca al transferir el depósito de la wallet a Bybit), detected_at, credited_at, block_number.
- `shares` — id, user_id, units, created_at (una fila por depósito; las unidades emitidas).
- `movements` — id, user_id, type (`deposit`|`withdrawal`|`adjust`), amount, units, nav, balance_after, created_at (libro mayor, auditoría).
- `withdrawals` — id, user_id, network, token, amount, units_to_burn, destination_address, status (`pending`|`approved`|`sent`|`rejected`), admin_note, requested_at, processed_at, tx_hash.
- `nav_snapshots` — id, total_equity, total_units, nav, bot_pnl_total, snapshot_at.
- `wallets` — id, seed_encrypted, network, created_at (una fila por cartera raíz).

Índices: `users.username`/`email` UNIQUE, `deposits.tx_hash` UNIQUE, `deposit_addresses.address` UNIQUE por network, `withdrawals` por status/user.

## 3. Auth y seguridad

- Registro: usuario + email + contraseña (≥8). Validaciones: usuario/email únicos, email válido.
- `password_hash()` (bcrypt) para contraseñas.
- Sesiones PHP nativas: `HttpOnly`, `Secure`, `SameSite=Lax`; `session_regenerate_id(true)` al login.
- CSRF token en todos los POST (formularios y endpoints de acción).
- Rate-limit de login/registro por IP (tabla `login_attempts` o equivalente).
- Roles: `admin` vs `investor`; `admin.php` exige role `admin` en cada request.
- El `EXPORT_TOKEN` actual se mantiene para el dashboard/WS del bot (no se toca).
- Secretos (mnemonic, claves): fuera de git, en `config/config.json` (o archivo aparte no versionado). Nunca imprimirlos en logs.
- `.gitignore` del workspace cubre `config/` y secretos (verificar antes de commitear).

## 4. Wallet HD y direcciones

- Cartera raíz BIP-44: `m/44'/60'/0'/0/{index}` (todas las redes EVM usan coin_type `60'`).
- El mnemonic se genera una sola vez al inicializar (comando CLI `wallet:init`), se cifra AES-256-GCM con un secreto del servidor y se guarda en `wallets.seed_encrypted`. El secreto vive en config del servidor.
- Por usuario, por red: se deriva una dirección con `derivation_index` único; se guarda en `deposit_addresses`.
- Generación bajo demanda (cuando el inversor pide la dirección de esa red en `/panel`).
- Direcciones derivadas: solo lectura en la chain; los fondos van directo a la wallet del operador.

## 5. Escáner de depósitos (daemon systemd `grid-bot-scanner.service`)

- Bucle cada ~30 s; por red configurada:
  - Poll al RPC principal (o API explorer Etherscan/BscScan con key) por `Transfer` recientes de los contratos USDT/USDC hacia las direcciones de depósito activas.
  - Contratos por red en config (networks). Default:
    - Ethereum: USDT `0xdAC17F958D2ee523a2206206994597C13D831ec7`, USDC `0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48`.
    - BSC: USDT `0x55d398326f99059fF775485246999027B3197955`, USDC `0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d`.
    - Otras EVM: mismas reglas, contratos+RPC en config.
- Confirmation depth configurable por red (default: Ethereum 12, BSC 15, otras 15).
- Proceso de cada tx detectada:
  1. Si `tx_hash` ya existe → ignorar (dedupe por UNIQUE).
  2. Si monto > 0 y no es depósito propio → insertar `deposits` (pending) y esperar confirmaciones.
  3. Al alcanzar la profundidad → status `credited`, emitir unidades en `shares` = monto / NAV actual, registrar `movements` (todo en una transacción DB).
  4. Si la tx se revierte en chain → `deposits.failed` (sin acreditar).
  5. Timeout máximo de espera ~60 min configurable.
- Tolerancia a fallos: RPC de respaldo por red; si ambos fallan, backoff y reintento; log vía `Core/Logger`.
- Nunca acreditar sin confirmaciones verificadas.

## 6. Contabilidad (NAV/unidades)

- Un solo fondo:
  - `NAV = (real_balance_del_bot + Σ depósitos acreditados con deployed=false) / unidades_totales`.
  - `unidades_totales = unidades_owner + Σ unidades_inversores`.
  - El flag `deployed` evita doble conteo: mientras el depósito está en la wallet on-chain cuenta a 1:1 en el numerador; cuando el admin lo transfiere a Bybit, `real_balance` sube el mismo importe y el depósito pasa a `deployed=true` (su monto deja de contar aparte).
- Inicialización (`accounting:init`): `NAV = 1.000`; `unidades_owner = capital_usd` del bot al iniciar.
- Depósito acreditado: `unidades = monto / NAV_actual` (no altera NAV).
- Cada ciclo del daemon: leer estado del bot (`real_balance`, `pnl_total` del status JSON/WS) → recalcular `NAV` → insertar `nav_snapshots`.
- Retiro: `units_to_burn = monto / NAV`; se queman al aprobar el retiro (no al solicitar), así el PnL sigue acumulando hasta la aprobación.
- Libro mayor: todo depósito/retiro con `nav` y `balance_after`; el saldo de un usuario = `Σ units × NAV − Σ retiros`, siempre reconciliable.

## 7. Retiros

- Inversor solicita: monto, red, token, dirección destino.
- Validaciones: monto ≤ equidad disponible, dirección válida para la red (longitud + checksum), red soportada, monto ≥ mínimo configurable.
- Estados: `pending → approved → sent` (con `tx_hash`) | `rejected` (con `admin_note`).
- Envío manual: el admin ejecuta la transferencia desde su wallet y registra el `tx_hash`; el sistema marca `sent`.
- Decisión: las unidades se queman **al aprobar** el retiro (no al solicitarlo ni al marcarlo `sent`), así el PnL deja de acumularse sobre el monto retirado desde el momento de la aprobación.

## 8. Panel admin

- Lista de usuarios: saldo, unidades, estado; suspender/activar.
- Depósitos: historial con tx_hash, red, confirmaciones, estado.
- Retiros pendientes: aprobar/rechazar con nota; al aprobar muestra monto y dirección; admin pega tx_hash → `sent`.
- Estado del fondo: NAV, equidad, unidades totales, PnL del bot.

## 9. Manejo de errores

- Scanner: RPC fallido → respaldo → backoff; nunca acreditar sin confirmaciones.
- Tx revertida → `failed`, sin acreditar.
- Dedupe de depósitos por `tx_hash` UNIQUE (imposible doble acreditación).
- Emisión/quema de unidades y movimientos en la misma transacción DB (consistencia).
- Claves/secretos fuera de git y de logs.
- Rate-limit y validación server-side en todo POST.

## 10. Pruebas (phpunit, patrón existente)

- Auth: registro válido, duplicados, login ok/fallido, CSRF, rate-limit, roles, suspensión.
- Wallet: derivación determinista (mismo index → misma dirección), dirección válida por red, cifrado/descifrado.
- Contabilidad: NAV inicial, emisión de unidades en depósito, quema en retiro, crecimiento proporcional del NAV, casos límite (monto 0, retiro > saldo, NAV constante).
- Escáner: parseo de txs, profundidad de confirmaciones, dedupe por tx_hash, tx revertida.
- Integración: depósito → acreditado → equidad crece; retiro → aprobado → sent; reconciliación de libro mayor.

## 11. Fuera de alcance (fase 2)

- Envío automático de retiros desde el servidor (firma de transacciones on-chain).
- Notificaciones por email/telegram.
- Apalancamiento o estrategias diferenciadas por usuario.
- KYC/verificación de identidad.
- Captcha.
