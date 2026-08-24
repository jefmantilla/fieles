<?php
/**
 * Módulo de Seguridad Avanzada para Producción/Nube
 * Protección contra XSS, CSRF, Clickjacking y Rate Limiting
 */

// Aplicar Cabeceras de Seguridad HTTP
function setSecurityHeaders() {
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self' https: data: 'unsafe-inline' 'unsafe-eval';");
}

// Generar Token CSRF por Sesión
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Validar Token CSRF
function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Escapar salidas para prevenir XSS y limpiar caracteres malformados (UTF-8)
function e($string) {
    if (!is_string($string)) {
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }

    // Normalizar secuencias malformadas de codificación mixta (ej. Mar├¡a G├│mez -> María Gómez)
    if (strpos($string, '├') !== false || strpos($string, 'Ã') !== false) {
        $string = str_replace(
            ['├¡', '├│', '├í', '├®', '├║', '├▒', '├┴', '├┬', '├═', '├ô', '├Ü', 'Ã¡', 'Ã©', 'Ã\xAD', 'Ã³', 'Ãº', 'Ã±'],
            ['í', 'ó', 'á', 'é', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'á', 'é', 'í', 'ó', 'ú', 'ñ'],
            $string
        );
    }

    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Sanitizar entradas generales
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    return $data;
}

// Generar Código Único de Referido
function generateUniqueCode($prefix = 'REF') {
    return strtoupper($prefix . '-' . bin2hex(random_bytes(4)));
}

// Rate Limiting basado en Sesión/IP
function checkRateLimit($actionKey, $maxAttempts = 10, $decaySeconds = 180) {
    $now = time();
    $sessionKey = 'rate_' . $actionKey;

    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = ['attempts' => 1, 'first_attempt' => $now];
        return true;
    }

    $data = $_SESSION[$sessionKey];
    if ($now - $data['first_attempt'] > $decaySeconds) {
        $_SESSION[$sessionKey] = ['attempts' => 1, 'first_attempt' => $now];
        return true;
    }

    if ($data['attempts'] >= $maxAttempts) {
        return false;
    }

    $_SESSION[$sessionKey]['attempts']++;
    return true;
}

// Resetear contador de Rate Limit (ej. tras login exitoso o reset manual)
function resetRateLimit($actionKey) {
    unset($_SESSION['rate_' . $actionKey]);
}
