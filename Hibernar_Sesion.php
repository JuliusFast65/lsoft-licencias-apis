<?php
// -----------------------------------------------------------------------------
// Hibernación de Sesiones ERP API
// Este script marca las sesiones como hibernadas cuando el ERP se va a hibernar,
// liberando temporalmente las licencias. El despertar se maneja automáticamente
// en Ping_Sesion.php cuando se detecta actividad.
// -----------------------------------------------------------------------------

// Setear en 1  para ver errores fatales, 0 cuando vaya a producción
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); // Aquí va E_ALL para ver todos los errores, 0 ninguno

header('Content-Type: application/json');
date_default_timezone_set('America/Guayaquil');

// Permite activar logs de depuración (en Debug_Config.php se define log_debug)
define('DEBUG_MODE', true);
require_once 'Debug_Config.php';
require_once '../shared/Conectar_BD.php';
require_once 'Validar_Firma.php';

try {
    // Conexión y transacción
    $mysqli = Conectar_BD();
    $mysqli->begin_transaction();
    
    // Validación de la firma en la petición
    $input = validarPeticion();

    $RUC        = $input['RUC'] ?? null;
    $Serie      = $input['Serie'] ?? null;
    $ping_token = $input['ping_token'] ?? null;

    if (!$RUC || !$ping_token) {
        throw new Exception('Datos incompletos: RUC y ping_token son requeridos.', 400);
    }
    
    log_debug("Hibernación - RUC: $RUC, Serie: $Serie");

    // Verificar que la sesión existe y está activa
    $sql_verificar = "SELECT id, tipo, usuario, fecha_inicio, ultima_actividad 
                      FROM sesiones_erp 
                      WHERE Ruc = ? AND ping_token = ?";
    $stmt_verificar = $mysqli->prepare($sql_verificar);
    $stmt_verificar->bind_param('ss', $RUC, $ping_token);
    $stmt_verificar->execute();
    $sesion = $stmt_verificar->get_result()->fetch_assoc();
    
    if (!$sesion) {
        throw new Exception('Sesión no encontrada o token inválido.', 404);
    }
    
    log_debug('Sesión encontrada: ' . $sesion['tipo'] . ' - Usuario: ' . $sesion['usuario']);

        // Marcar sesión como hibernada
    $sql_hibernar = "UPDATE sesiones_erp 
                     SET estado = 'H', 
                         fecha_hibernacion = NOW(),
                         ultima_actividad = NOW()
                     WHERE Ruc = ? AND ping_token = ?";
    $stmt_hibernar = $mysqli->prepare($sql_hibernar);
    $stmt_hibernar->bind_param('ss', $RUC, $ping_token);
    
    if (!$stmt_hibernar->execute()) {
        throw new Exception('Error al hibernar la sesión.', 500);
    }
    
    $mensaje = 'Sesión hibernada exitosamente.';
    log_debug('Sesión hibernada: ' . $sesion['tipo']);

    // Commit y respuesta
    $mysqli->commit();
    log_debug('Commit exitoso');

    $response = [
        'Fin'        => 'OK',
        'Mensaje'    => $mensaje,
        'ping_token' => $ping_token,
        'estado'     => 'H',
        'sesion'     => [
            'tipo'     => $sesion['tipo'],
            'usuario'  => $sesion['tipo'],
            'fecha_inicio' => $sesion['fecha_inicio']
        ]
    ];

    http_response_code(200);
    echo json_encode($response);
    log_debug('Respuesta enviada');

} catch (Exception $e) {
    if (isset($mysqli)) {
        $mysqli->rollback();
    }
    log_debug('Error: ' . $e->getMessage() . ' Rollback.');
    http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
    echo json_encode([ 'Fin' => 'Error', 'Mensaje' => $e->getMessage() ]);
} finally {
    if (isset($mysqli)) {
        $mysqli->close();
    }
}
