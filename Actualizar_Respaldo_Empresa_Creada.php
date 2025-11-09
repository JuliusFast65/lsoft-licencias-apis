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
    $required = ['RUC', 'RUC_Creado', 'Sistema', 'Nombre_Respaldo'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Falta campo obligatorio: $field", 400);
        }
    }

    $RUC = trim($input['RUC']);
    $RUC_Creado = trim($input['RUC_Creado']);
    $Sistema = strtoupper(trim($input['Sistema']));
    $Nombre_Respaldo = trim($input['Nombre_Respaldo']);
    $Usuario = isset($input['Usuario']) ? trim($input['Usuario']) : null;
    $Respaldo_BD_General = isset($input['Respaldo_BD_General']) ? (bool)$input['Respaldo_BD_General'] : false;
    
    // Validar Sistema
    if (!in_array($Sistema, ['FSOFT', 'LSOFT', 'LSOFTW'])) {
        throw new Exception('Sistema debe ser FSOFT, LSOFT o LSOFTW', 400);
    }
    
    // Función para parsear el nombre del respaldo
    function parsearNombreRespaldo($nombre, $sistema) {
        // Extraer solo el nombre del archivo (sin ruta)
        $nombre_archivo = basename($nombre);
        
        // Detectar si es otra unidad (contiene :\ o \\)
        $es_nube = !(strpos($nombre, ':\\') !== false || strpos($nombre, '\\\\') !== false);
        $ruta_completa = $es_nube ? null : $nombre;
        
        // Detectar si es BD general (contiene _general o _GENERAL)
        $es_bd_general = (stripos($nombre_archivo, '_general') !== false);
        
        // Extraer fecha según el formato
        $fecha = null;
        if ($sistema === 'FSOFT') {
            // Formato: XXXX_9999999999999_AAAAMMDD_HHMMSS.zip o ..._general.zip
            // Buscar patrón AAAAMMDD_HHMMSS (después del segundo _)
            // El formato es: XXXX_RUC_AAAAMMDD_HHMMSS...
            if (preg_match('/_\d{13}_(\d{8})_(\d{6})/', $nombre_archivo, $matches)) {
                $fecha_str = $matches[1] . ' ' . $matches[2];
                // Convertir AAAAMMDD HHMMSS a YYYY-MM-DD HH:MM:SS
                $fecha = substr($fecha_str, 0, 4) . '-' . substr($fecha_str, 4, 2) . '-' . substr($fecha_str, 6, 2) . ' ' .
                         substr($fecha_str, 9, 2) . ':' . substr($fecha_str, 11, 2) . ':' . substr($fecha_str, 13, 2);
            }
        } else {
            // LSOFT o LSOFTW: Formato: XXXX_9999999999999_DDMMAAAAHHMMSS_PW.BAK o ..._GENERAL.BAK
            // Buscar patrón DDMMAAAAHHMMSS (14 dígitos seguidos después del segundo _)
            // El formato es: XXXX_RUC_DDMMAAAAHHMMSS_...
            if (preg_match('/_\d{13}_(\d{2})(\d{2})(\d{4})(\d{2})(\d{2})(\d{2})_/', $nombre_archivo, $matches)) {
                // DD MM AAAA HH MM SS
                $fecha = $matches[3] . '-' . $matches[2] . '-' . $matches[1] . ' ' . 
                         $matches[4] . ':' . $matches[5] . ':' . $matches[6];
            }
        }
        
        if (!$fecha) {
            throw new Exception('No se pudo extraer la fecha del nombre del respaldo', 400);
        }
        
        return [
            'fecha' => $fecha,
            'es_nube' => $es_nube,
            'ruta_completa' => $ruta_completa,
            'es_bd_general' => $es_bd_general
        ];
    }
    
    // Parsear el nombre del respaldo
    $info_respaldo = parsearNombreRespaldo($Nombre_Respaldo, $Sistema);
    $Fecha = $info_respaldo['fecha'];
    $es_nube = $info_respaldo['es_nube'];
    $Ubicacion = $info_respaldo['ruta_completa'];
    $es_bd_general_por_nombre = $info_respaldo['es_bd_general'];
    
    // Determinar qué se actualiza
    // Si el nombre indica BD general, solo actualizar BD general
    // Si el nombre es empresa creada y Respaldo_BD_General = true, actualizar ambos
    if ($es_bd_general_por_nombre) {
        $actualizar_empresa_creada = false;
        $actualizar_bd_general = true;
    } else {
        $actualizar_empresa_creada = true;
        $actualizar_bd_general = $Respaldo_BD_General;
    }
    
    // Preparar valores según el tipo de respaldo
    if ($es_nube) {
        // Respaldo a nube
        if ($actualizar_empresa_creada) {
            $Fecha_Respaldo_Nube = $Fecha;
            $Usuario_Respaldo_Nube = $Usuario;
        } else {
            $Fecha_Respaldo_Nube = null;
            $Usuario_Respaldo_Nube = null;
        }
        $Fecha_Respaldo_Otra_Unidad = null;
        $Ubicacion_Respaldo_Otra_Unidad = null;
        $Usuario_Respaldo_Otra_Unidad = null;
        
        // BD general
        if ($actualizar_bd_general) {
            $Fecha_Respaldo_BD_General_Nube = $Fecha;
            $Usuario_Respaldo_BD_General_Nube = $Usuario;
        } else {
            $Fecha_Respaldo_BD_General_Nube = null;
            $Usuario_Respaldo_BD_General_Nube = null;
        }
        $Fecha_Respaldo_BD_General_Otra_Unidad = null;
        $Ubicacion_Respaldo_BD_General_Otra_Unidad = null;
        $Usuario_Respaldo_BD_General_Otra_Unidad = null;
    } else {
        // Respaldo a otra unidad
        if ($actualizar_empresa_creada) {
            $Fecha_Respaldo_Otra_Unidad = $Fecha;
            $Ubicacion_Respaldo_Otra_Unidad = $Ubicacion;
            $Usuario_Respaldo_Otra_Unidad = $Usuario;
        } else {
            $Fecha_Respaldo_Otra_Unidad = null;
            $Ubicacion_Respaldo_Otra_Unidad = null;
            $Usuario_Respaldo_Otra_Unidad = null;
        }
        $Fecha_Respaldo_Nube = null;
        $Usuario_Respaldo_Nube = null;
        
        // BD general
        if ($actualizar_bd_general) {
            $Fecha_Respaldo_BD_General_Otra_Unidad = $Fecha;
            $Ubicacion_Respaldo_BD_General_Otra_Unidad = $Ubicacion;
            $Usuario_Respaldo_BD_General_Otra_Unidad = $Usuario;
        } else {
            $Fecha_Respaldo_BD_General_Otra_Unidad = null;
            $Ubicacion_Respaldo_BD_General_Otra_Unidad = null;
            $Usuario_Respaldo_BD_General_Otra_Unidad = null;
        }
        $Fecha_Respaldo_BD_General_Nube = null;
        $Usuario_Respaldo_BD_General_Nube = null;
    }

    // Validar longitud de RUC (máximo 13 dígitos)
    if (strlen($RUC) > 13) {
        throw new Exception('RUC excede el límite de 13 caracteres', 400);
    }
    if (strlen($RUC_Creado) > 13) {
        throw new Exception('RUC_Creado excede el límite de 13 caracteres', 400);
    }

    // Validar formato de fecha
    if (!strtotime($Fecha)) {
        throw new Exception('Formato de fecha inválido extraído del nombre del respaldo', 400);
    }

    // Validar longitud de campos VARCHAR
    if (!empty($Usuario) && strlen($Usuario) > 100) {
        throw new Exception('Usuario excede el límite de 100 caracteres', 400);
    }
    if (!$es_nube && !empty($Ubicacion) && strlen($Ubicacion) > 500) {
        throw new Exception('Ubicacion (ruta del respaldo) excede el límite de 500 caracteres', 400);
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
        'SELECT id, Contador_Respaldos_Nube, Contador_Respaldos_Otra_Unidad, 
                Contador_Respaldos_BD_General_Nube, Contador_Respaldos_BD_General_Otra_Unidad 
         FROM Empresas_Creadas 
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

    // Actualizar respaldo de BD general a nube
    if (!empty($Fecha_Respaldo_BD_General_Nube)) {
        $updates[] = 'Fecha_Ultimo_Respaldo_BD_General_Nube = ?';
        $params[] = $Fecha_Respaldo_BD_General_Nube;
        $types .= 's';
        
        $updates[] = 'Contador_Respaldos_BD_General_Nube = Contador_Respaldos_BD_General_Nube + 1';
        
        if (!empty($Usuario_Respaldo_BD_General_Nube)) {
            $updates[] = 'Usuario_Respaldo_BD_General_Nube = ?';
            $params[] = $Usuario_Respaldo_BD_General_Nube;
            $types .= 's';
        }
    }

    // Actualizar respaldo de BD general a otra unidad
    if (!empty($Fecha_Respaldo_BD_General_Otra_Unidad)) {
        $updates[] = 'Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad = ?';
        $params[] = $Fecha_Respaldo_BD_General_Otra_Unidad;
        $types .= 's';
        
        $updates[] = 'Contador_Respaldos_BD_General_Otra_Unidad = Contador_Respaldos_BD_General_Otra_Unidad + 1';
        
        if (!empty($Ubicacion_Respaldo_BD_General_Otra_Unidad)) {
            $updates[] = 'Ubicacion_Respaldo_BD_General_Otra_Unidad = ?';
            $params[] = $Ubicacion_Respaldo_BD_General_Otra_Unidad;
            $types .= 's';
        }
        
        if (!empty($Usuario_Respaldo_BD_General_Otra_Unidad)) {
            $updates[] = 'Usuario_Respaldo_BD_General_Otra_Unidad = ?';
            $params[] = $Usuario_Respaldo_BD_General_Otra_Unidad;
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
                Usuario_Respaldo_Nube, Ubicacion_Respaldo_Otra_Unidad, Usuario_Respaldo_Otra_Unidad,
                Fecha_Ultimo_Respaldo_BD_General_Nube, Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad,
                Contador_Respaldos_BD_General_Nube, Contador_Respaldos_BD_General_Otra_Unidad,
                Usuario_Respaldo_BD_General_Nube, Ubicacion_Respaldo_BD_General_Otra_Unidad, Usuario_Respaldo_BD_General_Otra_Unidad
         FROM Empresas_Creadas 
         WHERE id = ?'
    );
    $stmt->bind_param('i', $registro['id']);
    $stmt->execute();
    $res = $stmt->get_result();
    $datos_actualizados = $res->fetch_assoc();
    $stmt->close();

    // Preparar mensaje base
    $mensaje = 'Información de respaldo actualizada exitosamente';
    
    // Verificar advertencia: si se respaldó a otra unidad de empresa creada y hay más de 7 días sin respaldo a nube
    if ($actualizar_empresa_creada && !$es_nube && !empty($datos_actualizados['Fecha_Ultimo_Respaldo_Nube'])) {
        // Calcular días desde el último respaldo a nube
        $fecha_nube = new DateTime($datos_actualizados['Fecha_Ultimo_Respaldo_Nube']);
        $fecha_actual = new DateTime();
        $dias_sin_respaldo_nube = $fecha_actual->diff($fecha_nube)->days;
        
        // Si han pasado más de 7 días, agregar advertencia
        if ($dias_sin_respaldo_nube > 7) {
            $mensaje .= '. Advertencia: No ha realizado respaldo a la nube en ' . $dias_sin_respaldo_nube . ' días. Está expuesto sin respaldo reciente en la nube.';
        }
    }
    
    // Preparar respuesta simplificada
    $respuesta = [
        'Fin' => 'OK',
        'Mensaje' => $mensaje,
        'RUC' => $RUC,
        'RUC_Creado' => $RUC_Creado,
        'Sistema' => $Sistema,
        'Nombre_Respaldo' => $Nombre_Respaldo,
        'Fecha_Extraida' => $Fecha,
        'Usuario' => $Usuario,
        'Ubicacion' => $es_nube ? 'NUBE' : $Ubicacion,
        'Respaldo_BD_General' => $es_bd_general_por_nombre ? 'solo' : ($actualizar_bd_general ? 'adicional' : null)
    ];
    
    // Agregar información de contadores y fechas actualizadas
    if ($actualizar_empresa_creada) {
        if ($es_nube) {
            $respuesta['Respaldo_Empresa_Creada'] = [
                'Fecha_Ultimo_Respaldo_Nube' => $datos_actualizados['Fecha_Ultimo_Respaldo_Nube'],
                'Contador_Respaldos_Nube' => (int)$datos_actualizados['Contador_Respaldos_Nube']
            ];
        } else {
            $respuesta['Respaldo_Empresa_Creada'] = [
                'Fecha_Ultimo_Respaldo_Otra_Unidad' => $datos_actualizados['Fecha_Ultimo_Respaldo_Otra_Unidad'],
                'Contador_Respaldos_Otra_Unidad' => (int)$datos_actualizados['Contador_Respaldos_Otra_Unidad'],
                'Ubicacion_Respaldo_Otra_Unidad' => $datos_actualizados['Ubicacion_Respaldo_Otra_Unidad']
            ];
        }
    }
    
    if ($actualizar_bd_general) {
        if ($es_nube) {
            $respuesta['Info_Respaldo_BD_General'] = [
                'Fecha_Ultimo_Respaldo_BD_General_Nube' => $datos_actualizados['Fecha_Ultimo_Respaldo_BD_General_Nube'],
                'Contador_Respaldos_BD_General_Nube' => (int)$datos_actualizados['Contador_Respaldos_BD_General_Nube']
            ];
        } else {
            $respuesta['Info_Respaldo_BD_General'] = [
                'Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad' => $datos_actualizados['Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad'],
                'Contador_Respaldos_BD_General_Otra_Unidad' => (int)$datos_actualizados['Contador_Respaldos_BD_General_Otra_Unidad'],
                'Ubicacion_Respaldo_BD_General_Otra_Unidad' => $datos_actualizados['Ubicacion_Respaldo_BD_General_Otra_Unidad']
            ];
        }
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

