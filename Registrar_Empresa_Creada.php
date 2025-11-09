<?php
// ----------------------------------------------------------------------------
// Registrar_Empresa_Creada.php
// Endpoint para registrar o actualizar el acceso a una empresa/base de datos
// utilizada en el sistema. Puede ser la propia empresa dueña de la licencia
// o una empresa creada por ella (ERP de escritorio)
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
require_once 'Obt_IP_Real.php';
require_once 'Validar_Firma.php';

try {
    // Validación de la firma en la petición
    $input = validarPeticion();
    
    // Validar campos obligatorios
    $required = ['RUC', 'RUC_Creado', 'Serie'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Falta campo obligatorio: $field", 400);
        }
    }

    // Asignar parámetros
    $RUC          = trim($input['RUC']);         // RUC de la empresa dueña de la licencia
    $RUC_Creado   = trim($input['RUC_Creado']);  // RUC de la empresa creada
    $Serie        = $input['Serie'];
    $Sistema      = !empty($input['Sistema']) ? strtoupper($input['Sistema']) : null; // Opcional, para rastreo
    $Nombre_Empresa = $input['Nombre_Empresa'] ?? '';
    $Maquina      = isset($input['Maquina']) ? substr($input['Maquina'], 0, 100) : '';
    $ip           = Obt_IP_Real();

    // Validar longitud de RUC (máximo 13 dígitos)
    if (strlen($RUC) > 13) {
        throw new Exception('RUC excede el límite de 13 caracteres', 400);
    }
    if (strlen($RUC_Creado) > 13) {
        throw new Exception('RUC_Creado excede el límite de 13 caracteres', 400);
    }

    // Validar que el RUC existe en la tabla Empresas
    $conn = Conectar_BD();
    if (!$conn) {
        throw new Exception('Error al conectar a la base de datos', 500);
    }
    $conn->set_charset('utf8mb4');
    $conn->begin_transaction();

    // Verificar que la empresa dueña existe
    $stmt = $conn->prepare('SELECT RUC FROM Empresas WHERE RUC = ?');
    $stmt->bind_param('s', $RUC);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        throw new Exception('EMPRESA DUEÑA NO REGISTRADA', 404);
    }
    $stmt->close();

    // Nota: Es válido que RUC_Creado sea igual a RUC.
    // La empresa dueña también puede usar su propia base de datos y necesita
    // ser registrada para rastrear accesos y respaldos.

    // Verificar si ya existe un registro para esta combinación (sin Sistema, ya que ahora es único por RUC + RUC_Creado)
    $stmt = $conn->prepare(
        'SELECT id, Nombre_Empresa, Sistema FROM Empresas_Creadas 
         WHERE RUC = ? AND RUC_Creado = ?'
    );
    $stmt->bind_param('ss', $RUC, $RUC_Creado);
    $stmt->execute();
    $res = $stmt->get_result();
    $existe = $res->fetch_assoc();
    $stmt->close();

    if ($existe) {
        // Actualizar registro existente
        // Si se proporciona Sistema, actualizarlo. Si no, mantener el existente.
        $sistema_final = $Sistema ?? $existe['Sistema'];
        
        $stmt = $conn->prepare(
            'UPDATE Empresas_Creadas SET 
                Serie = ?,
                Sistema = ?,
                Nombre_Empresa = COALESCE(?, Nombre_Empresa),
                Ultimo_Acceso = NOW(),
                IP = ?,
                Maquina = COALESCE(?, Maquina),
                Activa = 1
             WHERE id = ?'
        );
        $stmt->bind_param('sssssi', $Serie, $sistema_final, $Nombre_Empresa, $ip, $Maquina, $existe['id']);
        if (!$stmt->execute()) {
            throw new Exception('Error actualizando empresa creada: ' . $stmt->error, 500);
        }
        $accion = 'actualizada';
        $id_registro = $existe['id'];
    } else {
        // Crear nuevo registro
        $stmt = $conn->prepare(
            'INSERT INTO Empresas_Creadas 
                (RUC, RUC_Creado, Serie, Sistema, Nombre_Empresa, IP, Maquina, Fecha_Creacion, Ultimo_Acceso, Activa)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)'
        );
        $stmt->bind_param('sssssss', $RUC, $RUC_Creado, $Serie, $Sistema, $Nombre_Empresa, $ip, $Maquina);
        if (!$stmt->execute()) {
            throw new Exception('Error registrando empresa creada: ' . $stmt->error, 500);
        }
        $accion = 'registrada';
        $id_registro = $conn->insert_id;
    }
    $stmt->close();

    // Commit de la transacción
    $conn->commit();

    // Preparar respuesta
    $respuesta = [
        'Fin' => 'OK',
        'Mensaje' => "Empresa creada $accion exitosamente",
        'id' => $id_registro,
        'RUC' => $RUC,
        'RUC_Creado' => $RUC_Creado,
        'Sistema' => $Sistema ?? 'N/A',
        'Ultimo_Acceso' => date('Y-m-d H:i:s')
    ];

    http_response_code(200);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    
    $error = [
        'Fin' => 'Error',
        'Mensaje' => $e->getMessage()
    ];

    echo json_encode($error, JSON_UNESCAPED_UNICODE);
    log_debug('Error en Registrar_Empresa_Creada: ' . $e->getMessage());

} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

