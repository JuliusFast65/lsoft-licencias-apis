<?php
// ----------------------------------------------------------------------------
// Actualizar_Respaldo_Empresa_Creada.php
// Endpoint para actualizar información de respaldos de una empresa creada
// Soporta respaldos a la nube/servidor y respaldos a otra unidad/carpeta
// ----------------------------------------------------------------------------
ini_set('default_charset','UTF-8');
header('Content-Type: application/json; charset=utf-8');
ob_clean();

define('DEBUG_MODE', true);

ini_set('display_errors', DEBUG_MODE ? 1 : 0);
ini_set('display_startup_errors', DEBUG_MODE ? 1 : 0);
error_reporting(DEBUG_MODE ? E_ALL : 0);
date_default_timezone_set('America/Guayaquil');

require_once 'Debug_Config.php';
require_once '../shared/Conectar_BD.php';
require_once 'Validar_Firma.php';

try {
    // Validación de la firma en la petición
    $input = validarPeticion();
    
    // Validar campos obligatorios
    $required = ['RUC', 'RUC_Creado'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Falta campo obligatorio: $field", 400);
        }
    }

    $RUC = trim($input['RUC']);
    $RUC_Creado = trim($input['RUC_Creado']);
    
    // Parámetros para respaldo a nube
    $Fecha_Respaldo_Nube = isset($input['Fecha_Respaldo_Nube']) ? $input['Fecha_Respaldo_Nube'] : null;
    $Usuario_Respaldo_Nube = isset($input['Usuario_Respaldo_Nube']) ? trim($input['Usuario_Respaldo_Nube']) : null;
    
    // Parámetros para respaldo a otra unidad
    $Fecha_Respaldo_Otra_Unidad = isset($input['Fecha_Respaldo_Otra_Unidad']) ? $input['Fecha_Respaldo_Otra_Unidad'] : null;
    $Ubicacion_Respaldo_Otra_Unidad = isset($input['Ubicacion_Respaldo_Otra_Unidad']) ? trim($input['Ubicacion_Respaldo_Otra_Unidad']) : null;
    $Usuario_Respaldo_Otra_Unidad = isset($input['Usuario_Respaldo_Otra_Unidad']) ? trim($input['Usuario_Respaldo_Otra_Unidad']) : null;

    // Validar longitud de RUC (máximo 13 dígitos)
    if (strlen($RUC) > 13) {
        throw new Exception('RUC excede el límite de 13 caracteres', 400);
    }
    if (strlen($RUC_Creado) > 13) {
        throw new Exception('RUC_Creado excede el límite de 13 caracteres', 400);
    }

    // Validar que al menos se proporcione información de un tipo de respaldo
    if (empty($Fecha_Respaldo_Nube) && empty($Fecha_Respaldo_Otra_Unidad)) {
        throw new Exception('Debe proporcionar al menos Fecha_Respaldo_Nube o Fecha_Respaldo_Otra_Unidad', 400);
    }

    // Validar formato de fechas si se proporcionan
    if (!empty($Fecha_Respaldo_Nube) && !strtotime($Fecha_Respaldo_Nube)) {
        throw new Exception('Formato de fecha inválido en Fecha_Respaldo_Nube', 400);
    }
    if (!empty($Fecha_Respaldo_Otra_Unidad) && !strtotime($Fecha_Respaldo_Otra_Unidad)) {
        throw new Exception('Formato de fecha inválido en Fecha_Respaldo_Otra_Unidad', 400);
    }

    // Validar longitud de campos VARCHAR
    if (!empty($Usuario_Respaldo_Nube) && strlen($Usuario_Respaldo_Nube) > 100) {
        throw new Exception('Usuario_Respaldo_Nube excede el límite de 100 caracteres', 400);
    }
    if (!empty($Ubicacion_Respaldo_Otra_Unidad) && strlen($Ubicacion_Respaldo_Otra_Unidad) > 500) {
        throw new Exception('Ubicacion_Respaldo_Otra_Unidad excede el límite de 500 caracteres', 400);
    }
    if (!empty($Usuario_Respaldo_Otra_Unidad) && strlen($Usuario_Respaldo_Otra_Unidad) > 100) {
        throw new Exception('Usuario_Respaldo_Otra_Unidad excede el límite de 100 caracteres', 400);
    }

    // Conexión a la base de datos
    $conn = Conectar_BD();
    if (!$conn) {
        throw new Exception('Error al conectar a la base de datos', 500);
    }
    $conn->set_charset('utf8mb4');
    $conn->begin_transaction();

    // Verificar que existe el registro (sin Sistema, ya que ahora es único por RUC + RUC_Creado)
    $stmt = $conn->prepare(
        'SELECT id, Contador_Respaldos_Nube, Contador_Respaldos_Otra_Unidad FROM Empresas_Creadas 
         WHERE RUC = ? AND RUC_Creado = ?'
    );
    $stmt->bind_param('ss', $RUC, $RUC_Creado);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        throw new Exception('Empresa creada no encontrada', 404);
    }
    $registro = $res->fetch_assoc();
    $stmt->close();

    // Construir la consulta UPDATE dinámicamente según los parámetros proporcionados
    $updates = [];
    $params = [];
    $types = '';

    // Actualizar respaldo a nube
    if (!empty($Fecha_Respaldo_Nube)) {
        $updates[] = 'Fecha_Ultimo_Respaldo_Nube = ?';
        $params[] = $Fecha_Respaldo_Nube;
        $types .= 's';
        
        $updates[] = 'Contador_Respaldos_Nube = Contador_Respaldos_Nube + 1';
        
        if (!empty($Usuario_Respaldo_Nube)) {
            $updates[] = 'Usuario_Respaldo_Nube = ?';
            $params[] = $Usuario_Respaldo_Nube;
            $types .= 's';
        }
    }

    // Actualizar respaldo a otra unidad
    if (!empty($Fecha_Respaldo_Otra_Unidad)) {
        $updates[] = 'Fecha_Ultimo_Respaldo_Otra_Unidad = ?';
        $params[] = $Fecha_Respaldo_Otra_Unidad;
        $types .= 's';
        
        $updates[] = 'Contador_Respaldos_Otra_Unidad = Contador_Respaldos_Otra_Unidad + 1';
        
        if (!empty($Ubicacion_Respaldo_Otra_Unidad)) {
            $updates[] = 'Ubicacion_Respaldo_Otra_Unidad = ?';
            $params[] = $Ubicacion_Respaldo_Otra_Unidad;
            $types .= 's';
        }
        
        if (!empty($Usuario_Respaldo_Otra_Unidad)) {
            $updates[] = 'Usuario_Respaldo_Otra_Unidad = ?';
            $params[] = $Usuario_Respaldo_Otra_Unidad;
            $types .= 's';
        }
    }

    if (empty($updates)) {
        throw new Exception('No hay campos para actualizar', 400);
    }

    // Ejecutar UPDATE
    $sql = 'UPDATE Empresas_Creadas SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $params[] = $registro['id'];
    $types .= 'i';
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        throw new Exception('Error actualizando información de respaldo: ' . $stmt->error, 500);
    }
    $stmt->close();

    // Commit de la transacción
    $conn->commit();

    // Obtener valores actualizados para la respuesta
    $stmt = $conn->prepare(
        'SELECT Fecha_Ultimo_Respaldo_Nube, Fecha_Ultimo_Respaldo_Otra_Unidad, 
                Contador_Respaldos_Nube, Contador_Respaldos_Otra_Unidad,
                Usuario_Respaldo_Nube, Ubicacion_Respaldo_Otra_Unidad, Usuario_Respaldo_Otra_Unidad
         FROM Empresas_Creadas 
         WHERE id = ?'
    );
    $stmt->bind_param('i', $registro['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $datos_actualizados = $res->fetch_assoc();
    $stmt->close();

    // Preparar respuesta
    $respuesta = [
        'Fin' => 'OK',
        'Mensaje' => 'Información de respaldo actualizada exitosamente',
        'RUC' => $RUC,
        'RUC_Creado' => $RUC_Creado
    ];
    
    if (!empty($Fecha_Respaldo_Nube)) {
        $respuesta['Respaldo_Nube'] = [
            'Fecha_Ultimo_Respaldo_Nube' => $datos_actualizados['Fecha_Ultimo_Respaldo_Nube'],
            'Contador_Respaldos_Nube' => (int)$datos_actualizados['Contador_Respaldos_Nube'],
            'Usuario_Respaldo_Nube' => $datos_actualizados['Usuario_Respaldo_Nube']
        ];
    }
    
    if (!empty($Fecha_Respaldo_Otra_Unidad)) {
        $respuesta['Respaldo_Otra_Unidad'] = [
            'Fecha_Ultimo_Respaldo_Otra_Unidad' => $datos_actualizados['Fecha_Ultimo_Respaldo_Otra_Unidad'],
            'Contador_Respaldos_Otra_Unidad' => (int)$datos_actualizados['Contador_Respaldos_Otra_Unidad'],
            'Ubicacion_Respaldo_Otra_Unidad' => $datos_actualizados['Ubicacion_Respaldo_Otra_Unidad'],
            'Usuario_Respaldo_Otra_Unidad' => $datos_actualizados['Usuario_Respaldo_Otra_Unidad']
        ];
    }

    http_response_code(200);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    
    http_response_code($e->getCode() ?: 500);
    
    $error = [
        'Fin' => 'Error',
        'Mensaje' => $e->getMessage()
    ];

    echo json_encode($error, JSON_UNESCAPED_UNICODE);
    log_debug('Error en Actualizar_Respaldo_Empresa_Creada: ' . $e->getMessage());

} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

