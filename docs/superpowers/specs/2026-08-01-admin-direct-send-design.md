# Admin Direct Send — Especificación de Diseño

## Objetivo

Permitir al administrador enviar USDT/USDC directamente desde la wallet HD del fondo a cualquier dirección EVM (Ethereum/BSC), con validaciones, confirmación explícita y registro completo.

---

## Alcance

- **Incluye**: envío ERC20/BEP20 (USDT, USDC) desde la wallet maestra (m/44'/60'/0'/0/0) a dirección arbitraria, con validaciones, gas estimation, firma local y broadcast vía RPC público.
- **No incluye**: envíos programados, multi-firma, envíos masivos (batch), integración con hardware wallets.

---

## Arquitectura

### Flujo Principal

1. Admin accede a `/src/php/admin.php` → tarjeta "Envío directo"
2. Completa formulario: red (eth/bsc), token (USDT/USDC), dirección destino, monto
3. Marca checkbox confirmación → click "Enviar"
4. `AdminHttp::handle()` valida CSRF, parámetros, balance suficiente
5. `Wallet::signAndSendERC20()` deriva clave privada (m/44'/60'/0'/0/0), construye tx ERC20 `transfer`, estima gas, firma, broadcast vía `RpcClient::call('eth_sendRawTransaction')`
6. Guarda registro en tabla `admin_sends`
6. UI muestra resultado: éxito (tx_hash, link explorer) o error

### Componentes Afectados

| Componente | Cambio |
|------------|--------|
| `Schema.php` / `SqliteSchema.php` | Tabla `admin_sends` |
| `Wallet.php` | `signAndSendERC20(PDO, string $secret, string $network, string $token, string $to, float $amount): array{ok:bool, tx_hash?:string, error?:string}` |
| `RpcClient.php` | Usar `eth_sendRawTransaction` (ya existe `call`) |
| `AdminHttp.php` | Nuevo action `send_direct` |
| `admin.php` | Nueva tarjeta UI + formulario |
| Tests | Unit + integration |

---

## Base de Datos

### Tabla `admin_sends`

```sql
CREATE TABLE IF NOT EXISTS admin_sends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    network VARCHAR(20) NOT NULL,
    token VARCHAR(10) NOT NULL,
    amount DECIMAL(20,8) NOT NULL,
    destination_address VARCHAR(42) NOT NULL,
    tx_hash VARCHAR(66) DEFAULT '',
    status ENUM('pending','sent','failed') DEFAULT 'pending',
    error_message TEXT DEFAULT '',
    gas_used BIGINT DEFAULT 0,
    gas_price BIGINT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    INDEX idx_admin (admin_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**SQLite mirror** (tests):
```sql
CREATE TABLE admin_sends (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id INTEGER NOT NULL,
    network TEXT NOT NULL,
    token TEXT NOT NULL,
    amount REAL NOT NULL,
    destination_address TEXT NOT NULL,
    tx_hash TEXT DEFAULT '',
    status TEXT DEFAULT 'pending',
    error_message TEXT DEFAULT '',
    gas_used INTEGER DEFAULT 0,
    gas_price INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now')),
    sent_at TEXT
);
```

---

## Validaciones y Seguridad

| Validación | Detalle |
|------------|---------|
| CSRF | Token en formulario, verificado en `AdminHttp` |
| Confirmación explícita | Checkbox requerido "Confirmo que la dirección y monto son correctos" |
| Red soportada | `Networks::validateAddress(network, address)` + `Networks::all()` |
| Token | Solo USDT/USDC (configurable via `Networks::contracts()`) |
| Dirección destino | `^0x[0-9a-fA-F]{40}$` + checksum opcional |
| Monto | `> 0` y ≤ balance disponible en wallet (verificado via `eth_call` balanceOf) |
| Gas estimation | `eth_estimateGas` antes de firmar; mostrar al admin |
| Balance check | Rechazar si balance < amount + gas_cost |
| Nonce | Obtener via `eth_getTransactionCount` (wallet index 0) |
| Replay protection | Chain ID correcto por red (eth=1, bsc=56) |

---

## Manejo de Errores

| Escenario | Acción |
|-----------|--------|
| Balance insuficiente | `ok=false, error="Balance insuficiente en wallet (disponible: X)"` |
| Gas estimation falla | `ok=false, error="No se pudo estimar gas: <detalle>"` |
| RPC error broadcast | `ok=false, error="Error enviando tx: <mensaje RPC>"` |
| Nonce gap | Reintento automático hasta 3 veces con nonce actualizado |
| Dirección inválida | Validación temprana antes de firmar |

---

## UI (admin.php)

### Nueva Tarjeta: "Envío directo"

```html
<div class="card">
    <h2>Envío directo (USDT/USDC)</h2>
    <form method="post" id="sendForm">
        <input type="hidden" name="action" value="send_direct">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        
        <label>Red</label>
        <select name="network" required>
            <option value="eth">Ethereum (ERC20)</option>
            <option value="bsc">BNB Smart Chain (BEP20)</option>
        </select>
        
        <label>Token</label>
        <select name="token" required>
            <option value="USDT">USDT</option>
            <option value="USDC">USDC</option>
        </select>
        
        <label>Dirección destino</label>
        <input name="destination" placeholder="0x..." required pattern="^0x[0-9a-fA-F]{40}$">
        
        <label>Monto</label>
        <input name="amount" type="number" step="0.00000001" min="0.00000001" required>
        
        <div id="gasEstimate" class="m" style="display:none;"></div>
        
        <label style="display:flex;align-items:center;gap:8px;margin:12px 0;">
            <input type="checkbox" name="confirm" id="confirm" required>
            <span>Confirmo que la dirección y monto son correctos</span>
        </label>
        
        <button type="submit" class="b-ok" disabled>Enviar</button>
    </form>
</div>
```

### JavaScript (inline) para UX

- Al cambiar red/token/dirección/monto → `fetch` a endpoint `/src/php/admin.php?action=estimate_gas` → muestra gas estimado y habilita/deshabilita botón
- Checkbox habilita botón solo si todo válido

---

## Tests Requeridos

### Unitarios (`tests/php/Unit/Core/AdminHttpTest.php`)

- `testSendDirectValidParams` — happy path con mock RPC
- `testSendDirectInsufficientBalance` — balance check
- `testSendDirectInvalidAddress` — validación dirección
- `testSendDirectMissingConfirm` — checkbox requerido
- `testSendDirectRpcError` — error broadcast

### Integración (`tests/php/Integration/AdminDirectSendTest.php`)

- Flujo completo: formulario → firma → mock RPC → registro BD

---

## Consideraciones Operativas

- **PLATFORM_SECRET** requerido en entorno (ya existe)
- **Gas price**: usar `eth_gasPrice` RPC + 10% buffer
- **Explorer links**: generar links Etherscan/BscScan automáticamente en UI resultado
- **Logs**: registrar admin_id, params (excepto mnemonic), tx_hash, resultado
- **Rollback**: si firma OK pero broadcast falla, status='failed', error_message guardado

---

## Fuera de Alcance (Próximas Iteraciones)

- Multi-firma (2/3 admin keys)
- Envíos programados / recurrentes
- Batch sending (múltiples destinos en una tx)
- Integración Ledger/Trezor
- Notificaciones Telegram/Email al enviar