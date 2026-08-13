<?php
/**
 * SecurityHelpers.php - Funciones de seguridad para Grid Bot
 * 
 * Incluye:
 * - Generación y verificación de tokens CSRF
 * - Sanitización consistente de inputs
 * - Protección contra XSS, SQL Injection y otros ataques
 */

/**
 * Genera un token CSRF único para formularios
 * @return string Token CSRF
 */
function generateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * Verifica si el token CSRF es válido
 * @param string $token Token a verificar
 * @return bool True si es válido
 */
function verifyCsrfToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Regenera el token CSRF (usar después de login o acciones críticas)
 * @return string Nuevo token
 */
function regenerateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

/**
 * Sanitiza inputs de usuario de manera consistente
 * @param mixed $input Valor a sanitizar
 * @param string $type Tipo de dato esperado (string, int, float, email, url, symbol, html)
 * @param array $options Opciones adicionales (min, max, flags)
 * @return mixed Valor sanitizado
 */
function sanitizeInput($input, $type = 'string', $options = []) {
    if ($input === null) {
        return $options['default'] ?? null;
    }
    
    switch ($type) {
        case 'int':
            $filtered = filter_var($input, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => $options['min'] ?? null,
                    'max_range' => $options['max'] ?? null
                ]
            ]);
            return $filtered !== false ? $filtered : ($options['default'] ?? 0);
            
        case 'float':
            $filtered = filter_var($input, FILTER_VALIDATE_FLOAT, [
                'options' => [
                    'min_range' => $options['min'] ?? null,
                    'max_range' => $options['max'] ?? null
                ]
            ]);
            return $filtered !== false ? $filtered : ($options['default'] ?? 0.0);
            
        case 'email':
            $filtered = filter_var($input, FILTER_VALIDATE_EMAIL);
            return $filtered !== false ? $filtered : ($options['default'] ?? '');
            
        case 'url':
            $filtered = filter_var($input, FILTER_VALIDATE_URL);
            return $filtered !== false ? $filtered : ($options['default'] ?? '');
            
        case 'symbol':
            // Para símbolos de trading (ej: ETHUSDT)
            $cleaned = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$input));
            return substr($cleaned, 0, 20);
            
        case 'bool':
            return filter_var($input, FILTER_VALIDATE_BOOLEAN);
            
        case 'html':
            // Permite HTML seguro con purificación
            return htmlspecialchars(trim((string)$input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
        case 'raw':
            // Sin sanitización, solo trim
            return trim((string)$input);
            
        default:
            // String por defecto con XSS protection
            $flags = ENT_QUOTES | ENT_HTML5;
            if (!empty($options['strip_tags'])) {
                $input = strip_tags((string)$input);
            }
            return htmlspecialchars(trim((string)$input), $flags, 'UTF-8');
    }
}

/**
 * Sanitiza array recursivamente
 * @param array $arr Array a sanitizar
 * @param array $schema Esquema de tipos por clave
 * @return array Array sanitizado
 */
function sanitizeArray(array $arr, array $schema = []) {
    $result = [];
    
    foreach ($arr as $key => $value) {
        $cleanKey = sanitizeInput($key, 'string');
        
        if (is_array($value)) {
            $result[$cleanKey] = sanitizeArray($value, $schema[$key] ?? []);
        } elseif (isset($schema[$key])) {
            $result[$cleanKey] = sanitizeInput($value, $schema[$key]['type'] ?? 'string', $schema[$key] ?? []);
        } else {
            $result[$cleanKey] = sanitizeInput($value);
        }
    }
    
    return $result;
}

/**
 * Valida y sanitiza datos según un esquema
 * @param array $data Datos a validar
 * @param array $schema Esquema de validación
 * @return array ['valid' => bool, 'data' => array, 'errors' => array]
 */
function validateSchema(array $data, array $schema) {
    $errors = [];
    $sanitized = [];
    
    foreach ($schema as $field => $rules) {
        $value = $data[$field] ?? null;
        
        // Required check
        if (!empty($rules['required']) && ($value === null || $value === '')) {
            $errors[$field] = 'Campo requerido';
            continue;
        }
        
        if ($value === null) {
            $sanitized[$field] = $rules['default'] ?? null;
            continue;
        }
        
        // Sanitize
        $sanitized[$field] = sanitizeInput($value, $rules['type'] ?? 'string', $rules);
        
        // Additional validation
        if (!empty($rules['validate'])) {
            $callback = $rules['validate'];
            if (is_callable($callback) && !$callback($sanitized[$field])) {
                $errors[$field] = $rules['error_msg'] ?? 'Valor inválido';
            }
        }
        
        // Min/Max for strings
        if (($rules['type'] ?? '') === 'string') {
            if (isset($rules['min_length']) && strlen($sanitized[$field]) < $rules['min_length']) {
                $errors[$field] = "Mínimo {$rules['min_length']} caracteres";
            }
            if (isset($rules['max_length']) && strlen($sanitized[$field]) > $rules['max_length']) {
                $errors[$field] = "Máximo {$rules['max_length']} caracteres";
            }
        }
    }
    
    return [
        'valid' => empty($errors),
        'data' => $sanitized,
        'errors' => $errors
    ];
}

/**
 * Limpia output para prevenir XSS
 * @param string $string String a limpiar
 * @return string String limpio
 */
function escapeOutput($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Limpia output para JavaScript
 * @param string $string String a limpiar
 * @return string String limpio para JS
 */
function escapeJs($string) {
    return json_encode((string)$string, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

/**
 * Valida que un string sea un path seguro (sin directory traversal)
 * @param string $path Path a validar
 * @return bool True si es seguro
 */
function isSafePath($path) {
    // Prevenir directory traversal
    if (strpos($path, '..') !== false) {
        return false;
    }
    
    // Prevenir null bytes
    if (strpos($path, "\0") !== false) {
        return false;
    }
    
    // Solo permitir caracteres alfanuméricos, guiones, underscores y slashes
    return preg_match('/^[a-zA-Z0-9\/\._\-]+$/', $path);
}

/**
 * Genera un nonce para Content Security Policy
 * @return string Nonce base64
 */
function generateCspNonce() {
    return base64_encode(random_bytes(16));
}

/**
 * Verifica rate limiting básico
 * @param string $action Acción a limitar
 * @param int $maxRequests Máximo de peticiones
 * @param int $windowSeconds Ventana de tiempo en segundos
 * @return bool True si está permitido
 */
function checkRateLimit($action, $maxRequests = 10, $windowSeconds = 60) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $key = "rate_limit_{$action}";
    $now = time();
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'reset' => $now + $windowSeconds];
    }
    
    // Reset window expired
    if ($now > $_SESSION[$key]['reset']) {
        $_SESSION[$key] = ['count' => 0, 'reset' => $now + $windowSeconds];
    }
    
    // Check limit
    if ($_SESSION[$key]['count'] >= $maxRequests) {
        return false;
    }
    
    $_SESSION[$key]['count']++;
    return true;
}

/**
 * Headers de seguridad recomendados
 */
function sendSecurityHeaders() {
    // Prevenir clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    
    // Prevenir MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // XSS Protection
    header('X-XSS-Protection: 1; mode=block');
    
    // Referrer Policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Permissions Policy
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

/**
 * Log seguro (sin exponer datos sensibles)
 * @param string $message Mensaje a loguear
 * @param string $level Nivel de log (INFO, WARNING, ERROR)
 * @param string $logFile Archivo de log
 */
function secureLog($message, $level = 'INFO', $logFile = null) {
    // Remover posibles datos sensibles del mensaje
    $sensitivePatterns = [
        '/api[_-]?key["\']?\s*[:=]\s*["\']?[a-zA-Z0-9]+/i',
        '/password["\']?\s*[:=]\s*["\']?[^"\']+["\']/i',
        '/secret["\']?\s*[:=]\s*["\']?[^"\']+["\']/i',
        '/token["\']?\s*[:=]\s*["\']?[^"\']+["\']/i',
    ];
    
    $safeMessage = $message;
    foreach ($sensitivePatterns as $pattern) {
        $safeMessage = preg_replace($pattern, '[REDACTED]', $safeMessage);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$safeMessage}" . PHP_EOL;
    
    if ($logFile) {
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    } else {
        error_log($logEntry);
    }
}
