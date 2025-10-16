<?php
declare(strict_types=1);

/**
 * Archivo: Validar_Firma.php
 * --------------------------
 * Centraliza la validación de firma HMAC y el control de timestamp
 * para proteger todos los endpoints de la API contra peticiones
 * maliciosas y ataques de repetición.
 */

// -----------------------------------------------------------------------------
// Configuración de seguridad
// -----------------------------------------------------------------------------
// Clave secreta para generar/verificar firmas HMAC. Mantener fuera de repositorios públicos.
const SECRET_SIGNING_KEY    = 'sk_prod_t9K4gR7pW2sXvLzQnJbV3mY6fH1aD8cZ5gU9iO0eR2wP4lS6qT7sXvLzQnJbV3mY';
// Ventana de tolerancia para timestamp (en segundos) para evitar replay attacks.
const MAX_TIME_DIFF_SECONDS = 600;

/**
 * Valida la petición HTTP asegurando la presencia de cabeceras de seguridad,
 * comprueba la frescura del timestamp, verifica la firma HMAC,
 * sanea y decodifica el JSON.
 *
 * @return array  Datos decodificados del JSON (array asociativo).
 * @throws \Exception  Con código 400 (petición mal formada) o 401 (no autorizado).
 */
function validarPeticion(): array
{
    // -------------------------------------------------------------------------
    // 1) Lectura de cabeceras y cuerpo
    // -------------------------------------------------------------------------
    /** @var string|null $timestamp Valor de X-Timestamp (UNIX timestamp enviado por cliente). */
    $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? null;
    /** @var string|null $signature Firma HMAC enviada en X-Signature. */
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? null;
    /** @var string|false $rawBody Cuerpo crudo de la petición. */
    $rawBody   = file_get_contents('php://input');

    // Verificar presencia de todos los elementos necesarios
    if (empty($timestamp) || empty($signature) || $rawBody === false || $rawBody === '') {
        throw new \Exception('Faltan cabeceras de seguridad o cuerpo de la petición.', 400);
    }

    // -------------------------------------------------------------------------
    // 2) Prevención de replay attacks
    // -------------------------------------------------------------------------
    $currentTime = time();
    if (abs($currentTime - (int)$timestamp) > MAX_TIME_DIFF_SECONDS) {
        throw new \Exception('Timestamp de la petición expirado.', 401);
    }

    // -------------------------------------------------------------------------
    // 3) Cálculo y verificación de HMAC
    // -------------------------------------------------------------------------
    // Convertir el cuerpo a hexadecimal para consistencia en la firma
    $hexBody            = bin2hex($rawBody);
    $dataToSign         = $timestamp . '.' . $hexBody;
    $expectedSignature  = hash_hmac('sha256', $dataToSign, SECRET_SIGNING_KEY);

    // Comparación en tiempo constante para evitar ataques timing
    if (!hash_equals($expectedSignature, $signature)) {
        throw new \Exception('Firma inválida. Acceso denegado.', 401);
    }

    // -------------------------------------------------------------------------
    // 4) Saneamiento del JSON recibido
    // -------------------------------------------------------------------------
    // a) Normalizar saltos de línea a "\n" para json_decode
    $sanitized = str_replace(
        ["\r\n", "\r", "\n"],
        ['\\n', '\\n', '\\n'],
        $rawBody
    );
    // b) Escapar backslashes para evitar errores de parsing
    $sanitized = str_replace('\\', '\\\\', $sanitized);
    // c) Eliminar caracteres de control ASCII no imprimibles
    $sanitized = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $sanitized);

    // -------------------------------------------------------------------------
    // 5) Decodificación del JSON
    // -------------------------------------------------------------------------
    /** @var array|null $decoded Decodificación JSON a array asociativo. */
    $decoded = json_decode($sanitized, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new \Exception('JSON inválido: ' . json_last_error_msg(), 400);
    }

    // Retornar datos decodificados
    return $decoded;
}
