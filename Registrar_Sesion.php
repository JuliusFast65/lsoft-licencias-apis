<?php
// -----------------------------------------------------------------------------
// Sesión y Validación de Licencias API
// Este script procesa peticiones de sesión, valida firma HMAC y maneja
// inserción de sesiones de los ERPs de Listosoft por licencias solicitadas.
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
require_once 'Obt_IP_Real.php';

try {
    // Conexión y transacción
    $mysqli = Conectar_BD();
    $mysqli->begin_transaction();
    
    // Validación de la firma en la petición
    $input = validarPeticion();

    $RUC                   = trim($input['RUC'] ?? '');
    $Serie                 = $input['Serie'] ?? null;
    $usuario               = $input['usuario'] ?? null;
    $licencias_solicitadas = array_map('strtoupper', $input['licencias'] ?? []);
    $ping_token            = $input['ping_token'] ?? null;
    $RUC_Empresa_Creada    = !empty($input['RUC_Empresa_Creada']) ? trim($input['RUC_Empresa_Creada']) : '';
    $Nombre_Empresa_Creada = $input['Nombre_Empresa_Creada'] ?? '';

    if (empty($RUC) || !$usuario || empty($licencias_solicitadas)) {
        throw new Exception('Datos incompletos: RUC, usuario y licencias son requeridos.', 400);
    }

    // Validar longitud de RUC (máximo 13 dígitos)
    if (strlen($RUC) > 13) {
        throw new Exception('RUC excede el límite de 13 caracteres', 400);
    }

    // Validar longitud de RUC_Empresa_Creada si se proporciona
    if (!empty($RUC_Empresa_Creada) && strlen($RUC_Empresa_Creada) > 13) {
        throw new Exception('RUC_Empresa_Creada excede el límite de 13 caracteres', 400);
    }

    log_debug("Datos validados - RUC: $RUC, Serie: $Serie, Usuario: $usuario, RUC_Empresa_Creada: " . ($RUC_Empresa_Creada ?: 'no proporcionado'));

    // Mapeo códigos de licencias a descripciones para mensajes de bloqueo
    $tipo_descripcion = [
        'FSOFT_BA'  => 'Licencia básica',
        'FSOFT_RP'  => 'Licencia de nómina',
        'FSOFT_CE'  => 'Licencia de comprobantes electrónicos',
        'LSOFT_BA'  => 'Licencia básica',
        'LSOFT_RP'  => 'Licencia de nómina',
        'LSOFT_CE'  => 'Licencia de comprobantes electrónicos',
        'LSOFT_AF'  => 'Licencia de activos fijos',
        'LSOFT_OP'  => 'Licencia de producción',
        'LSOFT_OT'  => 'Licencia de órdenes de trabajo',
        'LSOFTW_BA' => 'Licencia básica (incluye CE)',
        'LSOFTW_PV' => 'Licencia básica PV (incluye CE)',
        'LSOFTW_RP' => 'Licencia de nómina',
    ];


    // Consulta cupos y sesiones
    $sql_cupos = "SELECT Cant_Lic_FSOFT_BA, Cant_Lic_FSOFT_CE, Cant_Lic_FSOFT_RP,
                   Cant_Lic_LSOFT_BA, Cant_Lic_LSOFT_CE, Cant_Lic_LSOFT_RP, Cant_Lic_LSOFT_AF, Cant_Lic_LSOFT_OP, Cant_Lic_LSOFT_OT,
                   Cant_Lic_LSOFTW_BA, Cant_Lic_LSOFTW_PV, Cant_Lic_LSOFTW_RP
            FROM Empresas WHERE Ruc = ?";
    $stmt_cupos = $mysqli->prepare($sql_cupos);
    $stmt_cupos->bind_param('s', $RUC);
    $stmt_cupos->execute();
    $cupos = $stmt_cupos->get_result()->fetch_assoc();
    if (!$cupos) {
        throw new Exception('Empresa no encontrada o sin licencias configuradas.', 404);
    }
    log_debug('Cupos de licencias obtenidos: ' . json_encode($cupos));
    log_debug('RUC para conteo: ' . $RUC . ', Serie para conteo: ' . ($Serie ?? 'NULL'));
    
    // Debug: contar sesiones totales vs sesiones con licencias válidas
    $stmt_debug_total = $mysqli->prepare("SELECT COUNT(*) as total FROM sesiones_erp WHERE Ruc = ?");
    $stmt_debug_total->bind_param('s', $RUC);
    $stmt_debug_total->execute();
    $total_sesiones = $stmt_debug_total->get_result()->fetch_assoc()['total'];
    
    $stmt_debug_validas = $mysqli->prepare("SELECT COUNT(*) as total FROM sesiones_erp se INNER JOIN Licencias l ON se.Ruc = l.RUC AND se.Serie = l.Serie WHERE se.Ruc = ?");
    $stmt_debug_validas->bind_param('s', $RUC);
    $stmt_debug_validas->execute();
    $total_validas = $stmt_debug_validas->get_result()->fetch_assoc()['total'];
    
    log_debug("Debug - Sesiones totales: $total_sesiones, Sesiones con licencias válidas: $total_validas");

    // Conteo de todas las sesiones (solo las que tienen licencias válidas)
    $stmt_conteo = $mysqli->prepare(
        "SELECT se.tipo, COUNT(DISTINCT se.Serie) AS total
         FROM sesiones_erp se
         INNER JOIN Licencias l ON se.Ruc = l.RUC AND se.Serie = l.Serie
         WHERE se.Ruc = ? AND se.Serie != ?
         GROUP BY se.tipo"
    );
    $stmt_conteo->bind_param('ss', $RUC, $Serie);
    log_debug('SQL de conteo de sesiones: SELECT se.tipo, COUNT(DISTINCT se.Serie) AS total FROM sesiones_erp se INNER JOIN Licencias l ON se.Ruc = l.RUC AND se.Serie = l.Serie WHERE se.Ruc = "' . $RUC . '" AND se.Serie != "' . ($Serie ?? 'NULL') . '" GROUP BY se.tipo');
    $stmt_conteo->execute();
    $sesiones_activas = [];
    $res_conteo = $stmt_conteo->get_result();
    
    // Debug: mostrar cada fila que se está contando
    log_debug('Debug - Filas de conteo de sesiones:');
    $filas_raw = [];
    while ($row = $res_conteo->fetch_assoc()) {
        $sesiones_activas[$row['tipo']] = $row['total'];
        $filas_raw[] = $row;
        log_debug("  Tipo: {$row['tipo']}, Total: {$row['total']}");
    }
    log_debug('Resultado raw de la consulta: ' . json_encode($filas_raw));
    log_debug('Conteo de sesiones activas obtenido: ' . json_encode($sesiones_activas));

    // Procesar ping_token y generar uno nuevo
    if ($ping_token) {
        log_debug('Eliminando sesiones anteriores para ping_token.');
        $stmt_del = $mysqli->prepare('DELETE FROM sesiones_erp WHERE ping_token = ?');
        $stmt_del->bind_param('s', $ping_token);
        $stmt_del->execute();
    }
    $nuevo_ping_token = hash('sha256', $RUC . ($Serie ?? 'WEB') . $usuario . microtime(true) . random_int(1000, 9999));
    log_debug('Nuevo ping_token: ' . $nuevo_ping_token);
    
    // Generar serial web ANTES de verificar cupos para asegurar que esté disponible
    $nuevo_serial_generado = null;
    if (empty($Serie) && !empty(array_filter($licencias_solicitadas, function($tipo) { return strpos($tipo, 'LSOFTW_') === 0; }))) {
        $nuevo_serial_generado = generarSerialUnico($mysqli);
        log_debug('Serial web generado anticipadamente: ' . $nuevo_serial_generado);
    }

    // Preparar inserciones
    $stmt_insert_sesion = $mysqli->prepare(
        'INSERT INTO sesiones_erp (RUC, Serie, usuario, tipo, ping_token, fecha_inicio, ultima_actividad)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt_insert_licencia = $mysqli->prepare(
        "INSERT INTO Licencias (RUC, Serie, Maquina, Sistema, Tipo_Licencia, Alta)
         VALUES (?, ?, ?, 'LSOFTW', 1, NOW())"
    );

    $licencias_permitidas = [];
    $licencias_bloqueadas = [];
    $detalle_bloqueos     = [];
    $bases_permitidas     = [];
    
    log_debug('Licencias solicitadas: ' . json_encode($licencias_solicitadas));

    // Pasada 1: Licencias base (_BA y LSOFTW_PV)
    foreach ($licencias_solicitadas as $tipo) {
        if (substr($tipo, -3) !== '_BA' && $tipo !== 'LSOFTW_PV') continue;
        $desc = $tipo_descripcion[$tipo] ?? $tipo;
        $serie_lic = $Serie;
        if (strpos($tipo, 'LSOFTW_') === 0 && empty($serie_lic)) {
            // Generar identificador único para la máquina web basado en el user agent
            $info_nav = $input['info_nav'] ?? 'nd';
            
            // Extraer navegador principal para identificación legible
            $navegador = 'Unknown';
            if (strpos($info_nav, 'Chrome') !== false) {
                $navegador = 'Chrome';
            } elseif (strpos($info_nav, 'Firefox') !== false) {
                $navegador = 'Firefox';
            } elseif (strpos($info_nav, 'Safari') !== false) {
                $navegador = 'Safari';
            } elseif (strpos($info_nav, 'Edge') !== false) {
                $navegador = 'Edge';
            } elseif (strpos($info_nav, 'IE') !== false) {
                $navegador = 'IE';
            }
            
            // Crear hash único del user agent completo para garantizar unicidad
            $hash_nav = substr(md5($info_nav), 0, 8); // Primeros 8 caracteres del hash para reducir colisiones
            
            // Formato: Web_[Navegador]_[Hash] (ej: Web_Chrome_a1b2c3d4)
            $nombre_maquina = 'Web_' . $navegador . '_' . $hash_nav;
            
            // VERIFICAR SI YA EXISTE UNA LICENCIA PARA ESTA MÁQUINA
            log_debug('Verificando licencia existente para máquina: ' . $nombre_maquina . ' y RUC: ' . $RUC);
            $stmt_check_existente = $mysqli->prepare(
                "SELECT Serie FROM Licencias 
                 WHERE RUC = ? AND Maquina = ? AND Sistema = 'LSOFTW' 
                 LIMIT 1"
            );
            $stmt_check_existente->bind_param('ss', $RUC, $nombre_maquina);
            $stmt_check_existente->execute();
            log_debug('SQL ejecutada: SELECT Serie FROM Licencias WHERE RUC = "' . $RUC . '" AND Maquina = "' . $nombre_maquina . '" AND Sistema = "LSOFTW"');
            $licencia_existente = $stmt_check_existente->get_result()->fetch_assoc();
            log_debug('Resultado de consulta: ' . ($licencia_existente ? 'Encontrado - Serie: ' . $licencia_existente['Serie'] : 'No encontrado'));
            
            if ($licencia_existente) {
                // REUTILIZAR SERIAL EXISTENTE
                $serie_lic = $licencia_existente['Serie'];
                // Actualizar el serial generado para usar el existente
                $nuevo_serial_generado = $serie_lic;
                log_debug('Reutilizando serial existente: ' . $serie_lic . ' para máquina: ' . $nombre_maquina);
            } else {
                log_debug('No se encontró licencia existente para máquina: ' . $nombre_maquina . '. Creando nueva.');
                // USAR SERIAL GENERADO ANTICIPADAMENTE O GENERAR UNO NUEVO
                if (!$nuevo_serial_generado) {
                    $nuevo_serial_generado = generarSerialUnico($mysqli);
                    log_debug('Serial web generado en el bucle: ' . $nuevo_serial_generado);
                }
                $serie_lic = $nuevo_serial_generado;
                
                log_debug('Intentando insertar nueva licencia - RUC: ' . $RUC . ', Serie: ' . $serie_lic . ', Maquina: ' . $nombre_maquina);
                
                $stmt_insert_licencia->bind_param('sss', $RUC, $serie_lic, $nombre_maquina);
                if (!$stmt_insert_licencia->execute()) {
                    $error_msg = 'Error al registrar licencia de navegador. MySQL Error: ' . $stmt_insert_licencia->error;
                    log_debug($error_msg);
                    throw new Exception($error_msg, 500);
                }
                
                log_debug('Licencia de navegador registrada exitosamente');
                
                // Hacer commit inmediato de la licencia para que sea visible en consultas posteriores
                $mysqli->commit();
                $mysqli->begin_transaction();
                log_debug('Commit inmediato de licencia realizado, nueva transacción iniciada');
            }
            
            $stmt_check_existente->close();
        }
        if (empty($serie_lic)) {
            $licencias_bloqueadas[] = $tipo;
            $detalle_bloqueos[] = "$desc: se requiere número de Serie.";
            continue;
        }
        $campo_cupo = 'Cant_Lic_' . $tipo;
        $disp       = $cupos[$campo_cupo] ?? 0;
        $en_uso     = $sesiones_activas[$tipo] ?? 0;
        log_debug("Verificando cupo para $tipo: campo='$campo_cupo', disponible=$disp, en_uso=$en_uso");
        if ($disp > 0 && $en_uso < $disp) {
            $stmt_insert_sesion->bind_param('sssss', $RUC, $serie_lic, $usuario, $tipo, $nuevo_ping_token);
            if ($stmt_insert_sesion->execute()) {
                $licencias_permitidas[]     = $tipo;
                $bases_permitidas[]         = $tipo;
                log_debug('Base registrada: ' . $tipo);
            } else {
                throw new Exception('Error al insertar sesión base ' . $tipo, 500);
            }
        } else {
            $licencias_bloqueadas[] = $tipo;
            if ($disp <= 0) {
                $detalle_bloqueos[] = "$desc no adquirida.";
                log_debug("$tipo bloqueada: no adquirida (disp=$disp)");
            } else {
                $detalle_bloqueos[] = "Sin cupos disponibles para $desc.";
                log_debug("$tipo bloqueada: sin cupos disponibles (disp=$disp, en_uso=$en_uso)");
            }
        }
    }

    // Pasada 2: Módulos (sin _BA y sin _PV para LSOFTW)
    foreach ($licencias_solicitadas as $tipo) {
        if (substr($tipo, -3) === '_BA' || $tipo === 'LSOFTW_PV') continue;
        $desc    = $tipo_descripcion[$tipo] ?? $tipo;
        // Para LSOFTW, verificar si hay alguna licencia básica disponible (BA o PV)
        if (strpos($tipo, 'LSOFTW_') === 0) {
            $tiene_base = false;
            foreach ($bases_permitidas as $base) {
                if (strpos($base, 'LSOFTW_') === 0) {
                    $tiene_base = true;
                    break;
                }
            }
            if (!$tiene_base) {
                $licencias_bloqueadas[] = $tipo;
                $detalle_bloqueos[] = "$desc requiere una licencia básica LSOFTW (BA o PV) activa.";
                continue;
            }
        } else {
            // Para otros sistemas, usar la lógica original
            $base_req = explode('_', $tipo)[0] . '_BA';
            $base_desc = $tipo_descripcion[$base_req] ?? $base_req;
            if (!in_array($base_req, $bases_permitidas)) {
                $licencias_bloqueadas[] = $tipo;
                $detalle_bloqueos[] = "$desc requiere la $base_desc activa.";
                continue;
            }
        }
        $serie_lic = (strpos($tipo, 'LSOFTW_') === 0) ? $nuevo_serial_generado : $Serie;
        if (empty($serie_lic)) {
            $licencias_bloqueadas[] = $tipo;
            $detalle_bloqueos[] = "$desc: se requiere número de Serie.";
            continue;
        }
        $campo_cupo = 'Cant_Lic_' . $tipo;
        $disp       = $cupos[$campo_cupo] ?? 0;
        $en_uso     = $sesiones_activas[$tipo] ?? 0;
        log_debug("Verificando cupo para módulo $tipo: campo='$campo_cupo', disponible=$disp, en_uso=$en_uso");
        if ($disp > 0 && $en_uso < $disp) {
            $stmt_insert_sesion->bind_param('sssss', $RUC, $serie_lic, $usuario, $tipo, $nuevo_ping_token);
            if ($stmt_insert_sesion->execute()) {
                $licencias_permitidas[] = $tipo;
                log_debug('Módulo registrado: ' . $tipo);
            } else {
                throw new Exception('Error al insertar sesión módulo ' . $tipo, 500);
            }
        } else {
            $licencias_bloqueadas[] = $tipo;
            if ($disp <= 0) {
                $detalle_bloqueos[] = "$desc no adquirida.";
                log_debug("$tipo bloqueada: no adquirida (disp=$disp)");
            } else {
                $detalle_bloqueos[] = "Sin cupos disponibles para $desc.";
                log_debug("$tipo bloqueada: sin cupos disponibles (disp=$disp, en_uso=$en_uso)");
            }
        }
    }

    // FASE 3: Registrar en Empresas_Creadas si hay licencias LSOFTW permitidas
    // Si se proporciona RUC_Empresa_Creada, registrar esa empresa. Si no, registrar la empresa dueña.
    $tiene_licencias_lsoftw = !empty(array_filter($licencias_permitidas, function($tipo) { 
        return strpos($tipo, 'LSOFTW_') === 0; 
    }));
    
    if ($tiene_licencias_lsoftw) {
        try {
            // Verificar si existe la tabla Empresas_Creadas
            $stmt_check_table = $mysqli->prepare(
                "SELECT 1 FROM information_schema.tables 
                 WHERE table_schema = DATABASE() 
                 AND table_name = 'Empresas_Creadas'"
            );
            $stmt_check_table->execute();
            $table_exists = $stmt_check_table->get_result()->num_rows > 0;
            $stmt_check_table->close();

            if ($table_exists) {
                // Determinar RUC_Creado: si se proporciona RUC_Empresa_Creada, usarlo; si no, usar el RUC dueña
                $RUC_Creado = !empty($RUC_Empresa_Creada) ? $RUC_Empresa_Creada : $RUC;
                $Sistema_LSOFTW = 'LSOFTW';
                
                // Obtener nombre de la empresa
                // Si se proporciona Nombre_Empresa_Creada, usarlo; si no, obtenerlo de la tabla Empresas
                $nombre_empresa = $Nombre_Empresa_Creada;
                if (empty($nombre_empresa)) {
                    // Intentar obtener el nombre de la empresa creada si es diferente a la dueña
                    if ($RUC_Creado !== $RUC) {
                        // Si es una empresa creada, el nombre debería venir en el parámetro
                        // Pero por si acaso, intentamos buscarlo (aunque probablemente no esté en Empresas)
                        $stmt_nombre = $mysqli->prepare('SELECT Nombre FROM Empresas WHERE RUC = ?');
                        $stmt_nombre->bind_param('s', $RUC_Creado);
                        $stmt_nombre->execute();
                        $res_nombre = $stmt_nombre->get_result();
                        if ($res_nombre->num_rows > 0) {
                            $row_nombre = $res_nombre->fetch_assoc();
                            $nombre_empresa = $row_nombre['Nombre'] ?? '';
                        }
                        $stmt_nombre->close();
                    } else {
                        // Si es la empresa dueña, obtener el nombre de la tabla Empresas
                        $stmt_nombre = $mysqli->prepare('SELECT Nombre FROM Empresas WHERE RUC = ?');
                        $stmt_nombre->bind_param('s', $RUC);
                        $stmt_nombre->execute();
                        $res_nombre = $stmt_nombre->get_result();
                        if ($res_nombre->num_rows > 0) {
                            $row_nombre = $res_nombre->fetch_assoc();
                            $nombre_empresa = $row_nombre['Nombre'] ?? '';
                        }
                        $stmt_nombre->close();
                    }
                }

                // Obtener Serie final (puede ser el generado para LSOFTW o el proporcionado)
                $serie_final = $nuevo_serial_generado ?? $Serie;
                if (empty($serie_final)) {
                    // Si no hay serie, intentar obtenerla de la última licencia LSOFTW registrada
                    $stmt_serie = $mysqli->prepare(
                        "SELECT Serie FROM Licencias 
                         WHERE RUC = ? AND Sistema = 'LSOFTW' 
                         ORDER BY Ultimo_Acceso DESC LIMIT 1"
                    );
                    $stmt_serie->bind_param('s', $RUC);
                    $stmt_serie->execute();
                    $res_serie = $stmt_serie->get_result();
                    if ($res_serie->num_rows > 0) {
                        $row_serie = $res_serie->fetch_assoc();
                        $serie_final = $row_serie['Serie'];
                    }
                    $stmt_serie->close();
                }

                if (!empty($serie_final)) {
                    // Obtener información de la máquina (para web, usar el nombre de máquina si está disponible)
                    $nombre_maquina = '';
                    if (isset($input['info_nav'])) {
                        $info_nav = $input['info_nav'];
                        $navegador = 'Unknown';
                        if (strpos($info_nav, 'Chrome') !== false) {
                            $navegador = 'Chrome';
                        } elseif (strpos($info_nav, 'Firefox') !== false) {
                            $navegador = 'Firefox';
                        } elseif (strpos($info_nav, 'Safari') !== false) {
                            $navegador = 'Safari';
                        } elseif (strpos($info_nav, 'Edge') !== false) {
                            $navegador = 'Edge';
                        }
                        $hash_nav = substr(md5($info_nav), 0, 8);
                        $nombre_maquina = 'Web_' . $navegador . '_' . $hash_nav;
                    }
                    
                    $ip = Obt_IP_Real();

                    // Verificar si ya existe un registro para esta combinación (sin Sistema, ya que ahora es único por RUC + RUC_Creado)
                    $stmt_check = $mysqli->prepare(
                        'SELECT id, Sistema FROM Empresas_Creadas 
                         WHERE RUC = ? AND RUC_Creado = ?'
                    );
                    $stmt_check->bind_param('ss', $RUC, $RUC_Creado);
                    $stmt_check->execute();
                    $res_check = $stmt_check->get_result();
                    $existe = $res_check->fetch_assoc();
                    $stmt_check->close();

                    if ($existe) {
                        // Actualizar registro existente
                        // Actualizar Sistema a LSOFTW ya que es el sistema más reciente desde el que se accedió
                        $stmt_upd = $mysqli->prepare(
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
                        $stmt_upd->bind_param('sssssi', $serie_final, $Sistema_LSOFTW, $nombre_empresa, $ip, $nombre_maquina, $existe['id']);
                        $stmt_upd->execute();
                        $stmt_upd->close();
                        log_debug("Empresa creada actualizada para LSOFTW: RUC=$RUC, RUC_Creado=$RUC_Creado (Sistema actualizado a LSOFTW)");
                    } else {
                        // Crear nuevo registro
                        $stmt_ins = $mysqli->prepare(
                            'INSERT INTO Empresas_Creadas 
                                (RUC, RUC_Creado, Serie, Sistema, Nombre_Empresa, IP, Maquina, Fecha_Creacion, Ultimo_Acceso, Activa)
                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)'
                        );
                        $stmt_ins->bind_param('sssssss', $RUC, $RUC_Creado, $serie_final, $Sistema_LSOFTW, $nombre_empresa, $ip, $nombre_maquina);
                        $stmt_ins->execute();
                        $stmt_ins->close();
                        log_debug("Empresa creada registrada para LSOFTW: RUC=$RUC, RUC_Creado=$RUC_Creado");
                    }
                }
            }
        } catch (Exception $e_creada) {
            // No fallar el registro de sesión si hay error al registrar empresa creada
            log_debug("Error al registrar empresa creada para LSOFTW: " . $e_creada->getMessage());
        }
    }

    // FASE 4: Commit y respuesta
    $mysqli->commit();
    log_debug('Commit exitoso');
    log_debug('Resumen final - Permitidas: ' . json_encode($licencias_permitidas) . ', Bloqueadas: ' . json_encode($licencias_bloqueadas));

    $response = [
        'Fin'                  => 'OK',
        'Mensaje'              => 'Sesión procesada.',
        'ping_token'           => $nuevo_ping_token,
        'nuevo_serial'         => $nuevo_serial_generado,
        'licencias_permitidas' => array_unique($licencias_permitidas),
        'licencias_bloqueadas' => array_unique($licencias_bloqueadas),
        'detalle_bloqueos'     => $detalle_bloqueos
    ];

    http_response_code(200);
    echo json_encode($response);
    log_debug('Respuesta enviada');

} catch (Exception $e) {
    // Solo hacer rollback en errores críticos de BD, no en errores de cupos
    if (strpos($e->getMessage(), 'Error al insertar') !== false || 
        strpos($e->getMessage(), 'Error al registrar licencia') !== false) {
        $mysqli->rollback();
        log_debug('Error crítico de BD: ' . $e->getMessage() . ' Rollback.');
    } else {
        // Para errores de cupos, no hacer rollback, solo log
        log_debug('Error de validación: ' . $e->getMessage() . ' Sin rollback.');
    }
    
    // Determinar si es error de cupos o error crítico
    $es_error_cupos = (strpos($e->getMessage(), 'Sin cupos') !== false || 
                      strpos($e->getMessage(), 'no adquirida') !== false ||
                      strpos($e->getMessage(), 'requiere la') !== false);
    
    if ($es_error_cupos) {
        // Error de cupos: retornar respuesta con serial pero sin sesiones
        $response = [
            'Fin'                  => 'OK',
            'Mensaje'              => 'Serial generado pero sin cupos disponibles.',
            'ping_token'           => $nuevo_ping_token ?? '',
            'nuevo_serial'         => $nuevo_serial_generado ?? '',
            'licencias_permitidas' => [],
            'licencias_bloqueadas' => $licencias_solicitadas,
            'detalle_bloqueos'     => [$e->getMessage()]
        ];
        
        http_response_code(200);
        echo json_encode($response);
        log_debug('Respuesta enviada con serial pero sin cupos.');
    } else {
        // Error crítico: retornar error
        http_response_code($e->getCode() > 0 ? $e->getCode() : 500);
        echo json_encode([ 'Fin' => 'Error', 'Mensaje' => $e->getMessage() ]);
    }
} finally {
    if ($mysqli) {
        $mysqli->close();
    }
}

//-----------------------------------------------------------------------------
// Función auxiliar: generar serial único
//-----------------------------------------------------------------------------
function generarSerialUnico(mysqli $conn): string
{
    $caracteres = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $len = strlen($caracteres);
    do {
        $serial = '';
        for ($i = 0; $i < 9; $i++) {
            $serial .= $caracteres[random_int(0, $len - 1)];
        }
        $stmt = $conn->prepare('SELECT 1 FROM Licencias WHERE Serie = ?');
        $stmt->bind_param('s', $serial);
        $stmt->execute();
        $result = $stmt->get_result();
    } while ($result->num_rows > 0);
    $stmt->close();
    return $serial;
}
