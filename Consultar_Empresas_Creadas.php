<?php
// ----------------------------------------------------------------------------
// Consultar_Empresas_Creadas.php
// Endpoint para consultar las empresas creadas por una empresa dueña de licencia
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
    if (empty($input['RUC'])) {
        throw new Exception("Falta campo obligatorio: RUC", 400);
    }

    $RUC = trim($input['RUC']);
    $Sistema = !empty($input['Sistema']) ? strtoupper($input['Sistema']) : null;
    $Solo_Activas = isset($input['Solo_Activas']) ? (bool)$input['Solo_Activas'] : true;
    $Dias_Sin_Acceso = isset($input['Dias_Sin_Acceso']) ? (int)$input['Dias_Sin_Acceso'] : null;

    // Validar longitud de RUC (máximo 13 dígitos)
    if (strlen($RUC) > 13) {
        throw new Exception('RUC excede el límite de 13 caracteres', 400);
    }

    // Conexión a la base de datos
    $conn = Conectar_BD();
    if (!$conn) {
        throw new Exception('Error al conectar a la base de datos', 500);
    }
    $conn->set_charset('utf8mb4');

    // Verificar que la empresa dueña existe
    $stmt = $conn->prepare('SELECT RUC, Nombre FROM Empresas WHERE RUC = ?');
    $stmt->bind_param('s', $RUC);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        throw new Exception('EMPRESA DUEÑA NO REGISTRADA', 404);
    }
    $empresa_dueno = $res->fetch_assoc();
    $stmt->close();

    // Construir consulta
    $sql = 'SELECT 
                id,
                RUC_Creado,
                RUC,
                Serie,
                Sistema,
                Nombre_Empresa,
                Fecha_Creacion,
                Ultimo_Acceso,
                IP,
                Maquina,
                Activa,
                Fecha_Ultimo_Respaldo_Nube,
                Fecha_Ultimo_Respaldo_Otra_Unidad,
                Contador_Respaldos_Nube,
                Contador_Respaldos_Otra_Unidad,
                Usuario_Respaldo_Nube,
                Ubicacion_Respaldo_Otra_Unidad,
                Usuario_Respaldo_Otra_Unidad,
                Fecha_Ultimo_Respaldo_BD_General_Nube,
                Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad,
                Contador_Respaldos_BD_General_Nube,
                Contador_Respaldos_BD_General_Otra_Unidad,
                Usuario_Respaldo_BD_General_Nube,
                Ubicacion_Respaldo_BD_General_Otra_Unidad,
                Usuario_Respaldo_BD_General_Otra_Unidad,
                DATEDIFF(NOW(), Ultimo_Acceso) AS Dias_Sin_Acceso,
                DATEDIFF(NOW(), Fecha_Ultimo_Respaldo_Nube) AS Dias_Sin_Respaldo_Nube,
                DATEDIFF(NOW(), Fecha_Ultimo_Respaldo_Otra_Unidad) AS Dias_Sin_Respaldo_Otra_Unidad,
                DATEDIFF(NOW(), Fecha_Ultimo_Respaldo_BD_General_Nube) AS Dias_Sin_Respaldo_BD_General_Nube,
                DATEDIFF(NOW(), Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad) AS Dias_Sin_Respaldo_BD_General_Otra_Unidad
            FROM Empresas_Creadas
            WHERE RUC = ?';
    
    $params = [$RUC];
    $types = 's';

    if ($Solo_Activas) {
        $sql .= ' AND Activa = 1';
    }

    // Nota: Sistema ahora es un campo informativo, no parte de la clave única
    // Se puede filtrar por Sistema si se necesita, pero no afecta la unicidad del registro
    if ($Sistema !== null) {
        $sql .= ' AND Sistema = ?';
        $params[] = $Sistema;
        $types .= 's';
    }

    if ($Dias_Sin_Acceso !== null) {
        $sql .= ' AND DATEDIFF(NOW(), Ultimo_Acceso) >= ?';
        $params[] = $Dias_Sin_Acceso;
        $types .= 'i';
    }

    $sql .= ' ORDER BY Ultimo_Acceso DESC';

    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();

    $empresas_creadas = [];
    while ($row = $res->fetch_assoc()) {
        $empresas_creadas[] = [
            'id' => (int)$row['id'],
            'RUC_Creado' => $row['RUC_Creado'],
            'RUC' => $row['RUC'],
            'Serie' => $row['Serie'],
            'Sistema' => $row['Sistema'],
            'Nombre_Empresa' => $row['Nombre_Empresa'],
            'Fecha_Creacion' => $row['Fecha_Creacion'],
            'Ultimo_Acceso' => $row['Ultimo_Acceso'],
            'IP' => $row['IP'],
            'Maquina' => $row['Maquina'],
            'Activa' => (bool)$row['Activa'],
            'Fecha_Ultimo_Respaldo_Nube' => $row['Fecha_Ultimo_Respaldo_Nube'],
            'Fecha_Ultimo_Respaldo_Otra_Unidad' => $row['Fecha_Ultimo_Respaldo_Otra_Unidad'],
            'Contador_Respaldos_Nube' => (int)$row['Contador_Respaldos_Nube'],
            'Contador_Respaldos_Otra_Unidad' => (int)$row['Contador_Respaldos_Otra_Unidad'],
            'Usuario_Respaldo_Nube' => $row['Usuario_Respaldo_Nube'],
            'Ubicacion_Respaldo_Otra_Unidad' => $row['Ubicacion_Respaldo_Otra_Unidad'],
            'Usuario_Respaldo_Otra_Unidad' => $row['Usuario_Respaldo_Otra_Unidad'],
            'Fecha_Ultimo_Respaldo_BD_General_Nube' => $row['Fecha_Ultimo_Respaldo_BD_General_Nube'],
            'Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad' => $row['Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad'],
            'Contador_Respaldos_BD_General_Nube' => (int)$row['Contador_Respaldos_BD_General_Nube'],
            'Contador_Respaldos_BD_General_Otra_Unidad' => (int)$row['Contador_Respaldos_BD_General_Otra_Unidad'],
            'Usuario_Respaldo_BD_General_Nube' => $row['Usuario_Respaldo_BD_General_Nube'],
            'Ubicacion_Respaldo_BD_General_Otra_Unidad' => $row['Ubicacion_Respaldo_BD_General_Otra_Unidad'],
            'Usuario_Respaldo_BD_General_Otra_Unidad' => $row['Usuario_Respaldo_BD_General_Otra_Unidad'],
            'Dias_Sin_Acceso' => (int)$row['Dias_Sin_Acceso'],
            'Dias_Sin_Respaldo_Nube' => $row['Dias_Sin_Respaldo_Nube'] !== null ? (int)$row['Dias_Sin_Respaldo_Nube'] : null,
            'Dias_Sin_Respaldo_Otra_Unidad' => $row['Dias_Sin_Respaldo_Otra_Unidad'] !== null ? (int)$row['Dias_Sin_Respaldo_Otra_Unidad'] : null,
            'Dias_Sin_Respaldo_BD_General_Nube' => $row['Dias_Sin_Respaldo_BD_General_Nube'] !== null ? (int)$row['Dias_Sin_Respaldo_BD_General_Nube'] : null,
            'Dias_Sin_Respaldo_BD_General_Otra_Unidad' => $row['Dias_Sin_Respaldo_BD_General_Otra_Unidad'] !== null ? (int)$row['Dias_Sin_Respaldo_BD_General_Otra_Unidad'] : null
        ];
    }
    $stmt->close();

    // Obtener estadísticas
    // Nota: Sistema ahora es un campo informativo opcional para filtrar
    $sql_stats = 'SELECT 
            COUNT(*) AS total,
            COUNT(CASE WHEN Activa = 1 THEN 1 END) AS activas,
            COUNT(CASE WHEN DATEDIFF(NOW(), Ultimo_Acceso) >= 30 THEN 1 END) AS sin_acceso_30_dias,
            COUNT(CASE WHEN Fecha_Ultimo_Respaldo_Nube IS NULL OR DATEDIFF(NOW(), Fecha_Ultimo_Respaldo_Nube) >= 7 THEN 1 END) AS sin_respaldo_nube_7_dias,
            COUNT(CASE WHEN Fecha_Ultimo_Respaldo_Otra_Unidad IS NULL OR DATEDIFF(NOW(), Fecha_Ultimo_Respaldo_Otra_Unidad) >= 7 THEN 1 END) AS sin_respaldo_otra_unidad_7_dias,
            COUNT(CASE WHEN Fecha_Ultimo_Respaldo_BD_General_Nube IS NULL OR DATEDIFF(NOW(), Fecha_Ultimo_Respaldo_BD_General_Nube) >= 7 THEN 1 END) AS sin_respaldo_bd_general_nube_7_dias,
            COUNT(CASE WHEN Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad IS NULL OR DATEDIFF(NOW(), Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad) >= 7 THEN 1 END) AS sin_respaldo_bd_general_otra_unidad_7_dias
         FROM Empresas_Creadas
         WHERE RUC = ?';
    
    $params_stats = [$RUC];
    $types_stats = 's';
    
    if ($Sistema !== null) {
        $sql_stats .= ' AND Sistema = ?';
        $params_stats[] = $Sistema;
        $types_stats .= 's';
    }
    
    $stmt_stats = $conn->prepare($sql_stats);
    if ($params_stats) {
        $stmt_stats->bind_param($types_stats, ...$params_stats);
    }
    $stmt_stats->execute();
    $stats = $stmt_stats->get_result()->fetch_assoc();
    $stmt_stats->close();

    // Preparar respuesta
    $respuesta = [
        'Fin' => 'OK',
        'RUC' => $RUC,
        'Nombre_Empresa_Dueno' => $empresa_dueno['Nombre'],
        'Total_Empresas' => (int)$stats['total'],
        'Empresas_Activas' => (int)$stats['activas'],
        'Sin_Acceso_30_Dias' => (int)$stats['sin_acceso_30_dias'],
        'Sin_Respaldo_Nube_7_Dias' => (int)$stats['sin_respaldo_nube_7_dias'],
        'Sin_Respaldo_Otra_Unidad_7_Dias' => (int)$stats['sin_respaldo_otra_unidad_7_dias'],
        'Sin_Respaldo_BD_General_Nube_7_Dias' => (int)$stats['sin_respaldo_bd_general_nube_7_dias'],
        'Sin_Respaldo_BD_General_Otra_Unidad_7_Dias' => (int)$stats['sin_respaldo_bd_general_otra_unidad_7_dias'],
        'Empresas_Creadas' => $empresas_creadas
    ];

    http_response_code(200);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    
    $error = [
        'Fin' => 'Error',
        'Mensaje' => $e->getMessage()
    ];

    echo json_encode($error, JSON_UNESCAPED_UNICODE);
    log_debug('Error en Consultar_Empresas_Creadas: ' . $e->getMessage());

} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

