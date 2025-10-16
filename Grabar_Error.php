<?php
// -----------------------------------------------------------------------------
// Endpoint para registrar errores desde el ERP (FSoft)
// -----------------------------------------------------------------------------
// Requiere que la petici�n est� firmada con HMAC usando clave secreta
// y se env�e en formato JSON
// -----------------------------------------------------------------------------

header('Content-Type: application/json');
date_default_timezone_set("America/Guayaquil");

// Activar logs de depuraci�n si se desea
define('DEBUG_MODE', true);
require_once 'Debug_Config.php';
require_once 'Validar_Firma.php';
require_once '../shared/Conectar_BD.php';

// -----------------------------------------------------------------------------
// Comienzo de debug
// -----------------------------------------------------------------------------
log_debug("---- Inicio: Registrar_Errors Endpoint ----");

// -----------------------------------------------------------------------------
// Validar firma y leer cuerpo JSON
// -----------------------------------------------------------------------------
log_debug("Validando petici�n entrante...");
try {
    $input = validarPeticion();  // ya devuelve el JSON decodificado
    log_debug("Petici�n validada con �xito: " . json_encode($input));
} catch (\Exception $e) {
    log_debug("Error en validarPeticion(): " . $e->getMessage());
    http_response_code($e->getCode());
    echo json_encode([
        "status" => "error",
        "mensaje" => $e->getMessage()
    ]);
    exit();
}

// -----------------------------------------------------------------------------
// Extraer campos del JSON
// -----------------------------------------------------------------------------
$RUC       = $input['RUC']       ?? '';
$Empresa   = $input['Empresa']   ?? '';
$Usuario   = $input['Usuario']   ?? '';
$Error     = $input['Error']     ?? '';
$Fuente    = $input['Fuente']    ?? '';
$Linea     = $input['Linea']     ?? '0';
$ErrorNum  = $input['ErrorNum']  ?? '0';
$Programa  = $input['Programa']  ?? '';
$Version   = $input['Version']   ?? '';

log_debug("Variables asignadas: RUC={$RUC}, Empresa={$Empresa}, Usuario={$Usuario}, Fuente={$Fuente}, Linea={$Linea}, ErrorNum={$ErrorNum}, Programa={$Programa}, Version={$Version}");

// -----------------------------------------------------------------------------
// Conectar a la base de datos
// -----------------------------------------------------------------------------
log_debug("Intentando conectar a BD...");
$conn = Conectar_BD(0);
if (!$conn) {
    log_debug("Error al conectar a BD: conexi�n inv�lida");
}
if (!$conn || !$conn->select_db("listosof_listosoft")) {
    $errorMsg = $conn ? $conn->error : 'Conexi�n inv�lida';
    log_debug("Error al seleccionar BD: " . $errorMsg);
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "mensaje" => "No se pudo conectar o seleccionar la base de datos"
    ]);
    exit();
}
log_debug("Conexi�n y selecci�n de BD exitosas");

// -----------------------------------------------------------------------------
// Insertar error en la tabla
// -----------------------------------------------------------------------------
$sql = "INSERT INTO Errores (
            PK_Errores,
            RUC,
            Empresa,
            Usuario,
            Error,
            Fuente,
            Linea,
            ErrorNum,
            Programa,
            Version,
            Fecha
        ) VALUES (
            NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP
        )";

log_debug("Preparando sentencia SQL para insertar error");
$stmt = $conn->prepare($sql);
if (!$stmt) {
    log_debug("Fallo al preparar la sentencia: " . $conn->error);
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "mensaje" => "Fallo al preparar la sentencia: " . $conn->error
    ]);
    exit();
}
log_debug("Sentencia preparada con �xito");

// -----------------------------------------------------------------------------
// Vincular par�metros
// -----------------------------------------------------------------------------
log_debug("Vinculando par�metros a la sentencia");
$stmt->bind_param(
    "sssssssss",
    $RUC,
    $Empresa,
    $Usuario,
    $Error,
    $Fuente,
    $Linea,
    $ErrorNum,
    $Programa,
    $Version
);

// -----------------------------------------------------------------------------
// Ejecutar y manejar resultado
// -----------------------------------------------------------------------------
log_debug("Ejecutando la sentencia INSERT");
if ($stmt->execute()) {
    log_debug("Error registrado correctamente en BD");
    echo json_encode([
        "status"  => "ok",
        "mensaje" => "Error registrado correctamente"
    ]);
} else {
    log_debug("Error al ejecutar INSERT: " . $stmt->error);
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "mensaje" => "No se pudo registrar el error: " . $stmt->error
    ]);
}

// -----------------------------------------------------------------------------
// Cierre de recursos y fin de debug
// -----------------------------------------------------------------------------
$stmt->close();
$conn->close();
log_debug("---- Fin: Registrar_Errors Endpoint ----");
?>
