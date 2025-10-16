<?php
// -----------------------------------------------------------------------------
// Ping de Sesiones ERP API
// Este script actualiza la actividad de las sesiones activas y reactiva
// automáticamente las sesiones hibernadas cuando se detecta actividad.
// -----------------------------------------------------------------------------

// Configuración de errores
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// Activa o desactiva el modo debug desde aquí
define('DEBUG_MODE', false); // Cambia a false para apagar logs

require_once("Debug_Config.php");
require("../shared/Conectar_BD.php");
require_once 'Validar_Firma.php';
date_default_timezone_set("America/Guayaquil");

try {
    // Validación de la firma en la petición
    $input = validarPeticion();
    
    $ping_token = $input["ping_token"] ?? '';

    // Validación de token
    if (!$ping_token) {
        throw new Exception('No se recibió el token', 400);
    }

    // Conexión a la base de datos
    $mysqli = Conectar_BD();
    if (!$mysqli) {
        throw new Exception('Error al conectar a la base de datos', 500);
    }

    // Iniciar transacción
    $mysqli->begin_transaction();

    // Buscar la sesión por ping_token
    $sql_buscar = "SELECT id, Ruc, Serie, tipo, usuario, estado, fecha_inicio, fecha_hibernacion 
                    FROM sesiones_erp 
                    WHERE ping_token = ?";
    $stmt_buscar = $mysqli->prepare($sql_buscar);
    $stmt_buscar->bind_param('s', $ping_token);
    $stmt_buscar->execute();
    $sesion = $stmt_buscar->get_result()->fetch_assoc();

    if (!$sesion) {
        throw new Exception('Token no encontrado', 404);
    }

    $fecha = date("Y-m-d H:i:s");
    $mensaje = '';
    $accion = '';

    // Verificar el estado de la sesión
    if ($sesion['estado'] === 'H') {
        // Sesión hibernada - reactivarla
        $sql_reactivar = "UPDATE sesiones_erp 
                          SET estado = 'A', 
                              ultima_actividad = ?, 
                              fecha_hibernacion = NULL 
                          WHERE ping_token = ?";
        $stmt_reactivar = $mysqli->prepare($sql_reactivar);
        $stmt_reactivar->bind_param('ss', $fecha, $ping_token);
        
        if (!$stmt_reactivar->execute()) {
            throw new Exception('Error al reactivar la sesión', 500);
        }

        $mensaje = 'Sesión reactivada exitosamente';
        $accion = 'reactivacion';
        log_debug("Sesión reactivada - RUC: {$sesion['Ruc']}, Serie: {$sesion['Serie']}");
        
    } else {
        // Sesión activa - actualizar última actividad
        $sql_actualizar = "UPDATE sesiones_erp 
                           SET ultima_actividad = ? 
                           WHERE ping_token = ?";
        $stmt_actualizar = $mysqli->prepare($sql_actualizar);
        $stmt_actualizar->bind_param('ss', $fecha, $ping_token);
        
        if (!$stmt_actualizar->execute()) {
            throw new Exception('Error al actualizar la sesión', 500);
        }

        $mensaje = 'Sesión actualizada';
        $accion = 'actualizacion';
        log_debug("Sesión actualizada - RUC: {$sesion['Ruc']}, Serie: {$sesion['Serie']}");
    }

    // Commit de la transacción
    $mysqli->commit();
    log_debug("Commit exitoso - Acción: $accion");

    // Respuesta exitosa
    $response = [
        'Fin' => 'OK',
        'Mensaje' => $mensaje,
        'accion' => $accion,
        'sesion' => [
            'estado' => $sesion['estado'] === 'H' ? 'A' : $sesion['estado'],
            'tipo' => $sesion['tipo'],
            'usuario' => $sesion['usuario'],
            'ultima_actividad' => $fecha
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
    echo json_encode([
        'Fin' => 'Error', 
        'Mensaje' => $e->getMessage()
    ]);
} finally {
    if (isset($mysqli)) {
        $mysqli->close();
    }
}
?>
