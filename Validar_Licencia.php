<?php
// ----------------------------------------------------------------------------
// Validación de licencias (versión refactorizada - iteración 3 con JSON I/O)
// ----------------------------------------------------------------------------
ini_set('default_charset','UTF-8');
header('Content-Type: application/json; charset=utf-8');
ob_clean();

define('DEBUG_MODE', true);  // true = muestra errores, false = los oculta

ini_set('display_errors', DEBUG_MODE ? 1 : 0);
ini_set('display_startup_errors', DEBUG_MODE ? 1 : 0);
error_reporting(DEBUG_MODE ? E_ALL : 0);
date_default_timezone_set('America/Guayaquil');

require_once 'Debug_Config.php';
require_once '../shared/Conectar_BD.php';
require_once 'ObtNombreUltAct.php';
require_once 'Obt_IP_Real.php';
require_once 'ObtToken.php';
require_once 'Validar_Firma.php';

try {
    // Validación de la firma en la petición
    $input = validarPeticion();
    
    // Validar campos obligatorios
    $required = ['RUC', 'Serie', 'Sistema'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new Exception("Falta campo obligatorio: $field", 400);
        }
    }

    // Asignar parámetros
    $Nombre        = $input['Nombre']         ?? '';
    $RUC           = $input['RUC'];
    $Telefono      = $input['Telefono']       ?? '';
    $eMail         = $input['eMail']          ?? '';
    $Serie         = $input['Serie'];
    $Version       = $input['Version']        ?? '';
    $Fecha_Version = $input['Fecha_Version']  ?? '';
    $Maquina       = isset($input['Maquina']) ? substr($input['Maquina'], 0, 30) : '';
    $Tipo_Licencia = $input['Tipo']           ?? '1';
    $Sistema       = strtoupper($input['Sistema'] ?? 'FSOFT');
    $FechaHora     = $input['FechaHora']      ?? '';
    $Codigo        = $input['Codigo']         ?? '';
    $Ciudad        = $input['Ciudad']         ?? '';
    $ip            = Obt_IP_Real();

    // Generación de token personalizado
    $Token = '';
    if ($FechaHora !== '') {
        $Token = ObtToken($Serie, substr($FechaHora, -1, 1), '');
    }

    // Inicializar conexión y transacción
    $conn = Conectar_BD();
    if (!$conn) {
        throw new Exception('Error al conectar a la base de datos', 500);
    }
    $conn->set_charset('utf8mb4');
    $conn->begin_transaction();

    // Lista negra hard-coded
    if ($Serie === '408982555') {
        throw new Exception('EN LISTA NEGRA', 403);
    }

    // Verificar existencia de empresa
    $stmt = $conn->prepare('SELECT * FROM Empresas WHERE RUC = ?');
    $stmt->bind_param('s', $RUC);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        throw new Exception('EMPRESA NO REGISTRADA', 404);
    }
    $regEmpresa = $res->fetch_assoc();

    // Soporte de nuevo licenciamiento según campos de tipo de licenciamiento
    $Soporta_Nuevo = false;
    $NuevoEsquemaLicenciamiento = 'N'; // Por defecto, licenciamiento antiguo
    
    if ($Sistema === 'FSOFT') {
        $tipoLicFsoft = trim($regEmpresa['Tipo_Lic_FSOFT'] ?? 'M');
        $Soporta_Nuevo = !empty($tipoLicFsoft) && $tipoLicFsoft === 'S';
        $NuevoEsquemaLicenciamiento = $Soporta_Nuevo ? 'S' : 'N';
    } elseif ($Sistema === 'LSOFT') {
        $tipoLicLsoft = trim($regEmpresa['Tipo_Lic_LSOFT'] ?? 'M');
        $Soporta_Nuevo = !empty($tipoLicLsoft) && $tipoLicLsoft === 'S';
        $NuevoEsquemaLicenciamiento = $Soporta_Nuevo ? 'S' : 'N';
    } elseif ($Sistema === 'LSOFTW') {
        // LSOFT Web siempre por sesión
        $Soporta_Nuevo = true;
        $NuevoEsquemaLicenciamiento = 'S';
    }

    // Actualizar datos de la empresa
    $stmtUpd = $conn->prepare(
        'UPDATE Empresas SET
            Nombre = ?,
            Telefono = ?,
            Version_FSoft = IF(? = \'FSOFT\', ?, Version_FSoft),
            Version_LSoft = IF(? = \'LSOFT\', ?, Version_LSoft),
            eMail = ?,
            Ciudad = ?,
            Codigo = ?,
            Sistema = ?
         WHERE RUC = ?'
    );
    $stmtUpd->bind_param(
        'sssssssssss',
        $Nombre,
        $Telefono,
        $Sistema,
        $Version,
        $Sistema,
        $Version,
        $eMail,
        $Ciudad,
        $Codigo,
        $Sistema,
        $RUC
    );
    if (!$stmtUpd->execute()) {
        throw new Exception('Error actualizando empresa: ' . $stmtUpd->error, 500);
    }

    // Chequeo de versión vs suscripción
    $Fin_Suscripcion = date("Y-m-d", strtotime($regEmpresa['Fin_Suscripcion']));
    if (!empty($Fecha_Version)) {
        $fLiberacion  = date('Y-m-d', strtotime($Fecha_Version));
        $fInicioSub   = date('Y-m-d', strtotime($regEmpresa['Inicio_Suscripcion']));
        if ($fLiberacion > $Fin_Suscripcion) {
            throw new Exception("DISPONE DE ACTUALIZACION NO AUTORIZADA ".$Version." ".$fLiberacion." ".$fInicioSub." al ".$Fin_Suscripcion, 403);
        }
    }

    // Obtener mensaje de Listosoft para la empresa
    $Mensaje_de_Listosoft = '';
    if ($regEmpresa['En_Lista_Negra']) {
       // tomo el mensaje para mostrarlo al final
       $Mensaje_de_Listosoft = $regEmpresa['Motivo_Lista_Negra'];
       if (strpos($Mensaje_de_Listosoft, 'EN LISTA NEGRA') !== false) {
            throw new Exception('EN LISTA NEGRA', 403);
       }
    }
      
    // Preparar datos para correo
    $to      = 'jsveloz@listosoft.com,ialvarez@listosoft.com,jyunga@listosoft.com';
    $subject = 'Nueva máquina agregada a www.listosoft.com';
    $headers = "From: Julio Veloz <pepito@desarrolloweb.com>\r\n";
    $body    = sprintf(
        "Empresa: %s\nCodigo: %s\nRUC: %s\nTelefono: %s\neMail: %s\nCiudad: %s\nSerie: %s\nMaquina: %s\nIP: %s\nTipo_Licencia: %s\nSistema: %s\nFecha: %s\n",
        $Nombre,
        $Codigo,
        $RUC,
        $Telefono,
        $eMail,
        $Ciudad,
        $Serie,
        $Maquina,
        $ip,
        $Tipo_Licencia,
        $Sistema,
        date('Y-m-d H:i:s')
    );

    // Validación y registro de licencias
    $nuevoVersionAvailable = null;
    if ($Soporta_Nuevo) {
        // Nuevo licenciamiento
        $stmtLic = $conn->prepare(
            'SELECT Cantidad_de_Accesos FROM Licencias WHERE RUC = ? AND Serie = ? AND Sistema = ?'
        );
        $stmtLic->bind_param('sss', $RUC, $Serie, $Sistema);
        $stmtLic->execute();
        $resLic = $stmtLic->get_result();
        if ($resLic->num_rows === 1) {
            // Actualizar acceso
            $info = $resLic->fetch_assoc();
            $count = $info['Cantidad_de_Accesos'] + 1;
            $stmtUpdL = $conn->prepare(
                'UPDATE Licencias SET Ultimo_Acceso = NOW(), Maquina = ?, IP = ?, Tipo_Licencia = ?, Cantidad_de_Accesos = ? WHERE RUC = ? AND Serie = ? AND Sistema =?'
            );
            $stmtUpdL->bind_param('sssssss', $Maquina, $ip, $Tipo_Licencia,  $count, $RUC, $Serie, $Sistema);
            if (!$stmtUpdL->execute()) {
                throw new Exception('Error actualizando licencia: ' . $stmtUpdL->error, 500);
            }
        } else {
            // Registrar nueva licencia
            $stmtIns = $conn->prepare(
                'INSERT INTO Licencias (RUC, Serie, Maquina, Alta, IP, Tipo_Licencia, Sistema, Ultimo_Acceso, Cantidad_de_Accesos) VALUES (?, ?, ?, NOW(), ?, ?, ?, NOW(), 1)'
            );
            $stmtIns->bind_param('ssssss', $RUC, $Serie, $Maquina, $ip, $Tipo_Licencia, $Sistema);
            if (!$stmtIns->execute()) {
                throw new Exception('Error registrando licencia: ' . $stmtIns->error, 500);
            }
            // Enviar correo
            if (!mail($to, $subject, $body, $headers)) {
                log_debug('Fallo envío notificación.');
            }
        }
    } else {
        // Licenciamiento antiguo (por máquina/slot)
        $fieldMax = $Sistema === 'FSOFT' ? 'Cant_Lic_FSOFT_BA' : 'Cant_Lic_LSOFT_BA';
        $max      = (int)$regEmpresa[$fieldMax];
        
                 // Verificar si ya existe una licencia con la misma serie, sistema y RUC
         $stmtExist = $conn->prepare('SELECT COUNT(*) as total FROM Licencias WHERE RUC = ? AND Serie = ? AND Sistema = ?');
         $stmtExist->bind_param('sss', $RUC, $Serie, $Sistema);
         $stmtExist->execute();
         $resExist = $stmtExist->get_result();
         $totalExist = $resExist->fetch_assoc()['total'];
         
         if ($totalExist >= 1) {
             // La licencia ya existe (puede haber duplicados)
             
             // Si hay múltiples registros duplicados, eliminar todos excepto el más reciente
             if ($totalExist > 1) {
                 $stmtDelete = $conn->prepare('DELETE l1 FROM Licencias l1 
                     INNER JOIN Licencias l2 ON l1.RUC = l2.RUC AND l1.Serie = l2.Serie AND l1.Sistema = l2.Sistema 
                     WHERE l1.RUC = ? AND l1.Serie = ? AND l1.Sistema = ? AND l1.Ultimo_Acceso < l2.Ultimo_Acceso');
                 $stmtDelete->bind_param('sss', $RUC, $Serie, $Sistema);
                 if (!$stmtDelete->execute()) {
                     throw new Exception('Error eliminando duplicados: ' . $stmtDelete->error, 500);
                 }
             }
             
             // Obtener el registro que quedó (el más reciente)
             $stmtGet = $conn->prepare('SELECT * FROM Licencias WHERE RUC = ? AND Serie = ? AND Sistema = ?');
             $stmtGet->bind_param('sss', $RUC, $Serie, $Sistema);
             $stmtGet->execute();
             $info = $stmtGet->get_result()->fetch_assoc();
             $count = $info['Cantidad_de_Accesos'] + 1;
             
             // Actualizar el registro
             $stmtUpdL = $conn->prepare(
                 'UPDATE Licencias SET Ultimo_Acceso = NOW(), Maquina = ?, IP = ?, Tipo_Licencia = ?, Cantidad_de_Accesos = ? WHERE RUC = ? AND Serie = ? AND Sistema = ?'
             );
             $stmtUpdL->bind_param('sssssss', $Maquina, $ip, $Tipo_Licencia, $count, $RUC, $Serie, $Sistema);
             if (!$stmtUpdL->execute()) {
                 throw new Exception('Error actualizando licencia antigua: ' . $stmtUpdL->error, 500);
             }
        } else {
            // La licencia no existe, verificar si hay cupo disponible
            $stmtCnt = $conn->prepare('SELECT COUNT(*) AS total FROM Licencias WHERE RUC = ? AND Sistema = ?');
            $stmtCnt->bind_param('ss', $RUC, $Sistema);
            $stmtCnt->execute();
            $total = $stmtCnt->get_result()->fetch_assoc()['total'];
            
            if ($total >= $max) {
                throw new Exception("EXCEDE CANTIDAD DE LICENCIAS $max", 403);
            }
            
            // Hay cupo disponible, agregar nueva licencia
            $stmtIns2 = $conn->prepare(
                'INSERT INTO Licencias (RUC, Serie, Maquina, Alta, IP, Tipo_Licencia, Sistema, Ultimo_Acceso, Cantidad_de_Accesos) VALUES (?, ?, ?, NOW(), ?, ?, ?, NOW(), 1)'
            );

            // Verifica que prepare() no fallara
            if (!$stmtIns2) {
                log_debug('Prepare failed', ['error' => $conn->error]);
                throw new Exception('Error en prepare(): ' . $conn->error, 500);
            }

            $stmtIns2->bind_param('ssssss', $RUC, $Serie, $Maquina, $ip, $Tipo_Licencia, $Sistema);
            if (!$stmtIns2->execute()) {
                throw new Exception('Error registrando licencia antigua: ' . $stmtIns2->error, 500);
            }
            
            // Enviar correo solo para nuevas licencias
            if (!mail($to, $subject, $body, $headers)) {
                log_debug('Fallo envío notificación.');
            }
        }
    }

    // Obtener última versión del sistema
    $Mensaje_Nueva_Version = '';
    $UltimaVersion         = substr(ObtNombreUltAct3($conn, $Sistema), 8, 6);

    // Si $UltimaVersion existe y es mayor que la versión actual
    if (!empty($UltimaVersion) && version_compare($UltimaVersion, $Version, '>')) {
        $hoy = date("Y-m-d");
        if (empty($regEmpresa['Fin_Suscripcion']) || $hoy > $Fin_Suscripcion) {
            $Mensaje_Nueva_Version = "NUEVA VERSION DISPONIBLE {$UltimaVersion}";
        } else {
            $Mensaje_Nueva_Version = "INSTALAR VERSION NUEVA {$UltimaVersion}";
        }
        // Inicializo la variable para la respuesta JSON
        $nuevaVersionAvailable = $UltimaVersion;
    }
    
    // Commit de la transacción
    $conn->commit();
    
    // Armo el mensaje de salida
    if (empty($Mensaje_de_Listosoft)) {
        $Mensaje = "Licencia validada {$Fin_Suscripcion} {$Token} {$Mensaje_Nueva_Version} |";
    } else {
        $Mensaje = "Licencia validada {$Fin_Suscripcion} {$Token} {$Mensaje_de_Listosoft}";
    }
    
    // Preparo el array de respuesta
    $respuesta = [
        'Fin'                        => 'OK',
        'Mensaje'                    => $Mensaje,
        'Token'                      => $Token,
        'Fin_Suscripcion'            => $Fin_Suscripcion,
        'NuevoEsquemaLicenciamiento' => $NuevoEsquemaLicenciamiento,
    ];

    // Si hay una nueva versión, la pongo en la respuesta
    if (!empty($nuevaVersionAvailable)) {
        $respuesta['NuevaVersion'] = $nuevaVersionAvailable;
    }

    // Por si hay caracteres especiales que rompan el JSON
    $respuesta = utf8ize($respuesta);

    // Código HTTP
    http_response_code(200);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Exception $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->rollback();
    }
    
    $code = $e->getCode() ?: 500;
    http_response_code($code);
    
    $error = [
        'Fin'                        => 'Error',
        'Mensaje'                    => $e->getMessage(),
        'Token'                      => $Token ?? '',
        'Fin_Suscripcion'            => $Fin_Suscripcion ?? '',
        'NuevoEsquemaLicenciamiento' => $NuevoEsquemaLicenciamiento ?? 'N',
    ];

    echo json_encode($error);

} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}


// Función utf8ize mejorada
function utf8ize($data) {
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = utf8ize($v);
        }
    } elseif (is_string($data)) {
        // Detecta la codificación real
        $enc = mb_detect_encoding(
            $data,
            ['UTF-8','ISO-8859-1','Windows-1252'],
            true
        );
        // Si detecta una codificación válida y no es UTF-8, conviértelo
        if ($enc && $enc !== 'UTF-8') {
            return mb_convert_encoding($data, 'UTF-8', $enc);
        }
        // Ya era UTF-8 (o no se detectó), devuélvelo tal cual
        return $data;
    }
    return $data;
}
