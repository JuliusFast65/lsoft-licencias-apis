#!/usr/bin/php
<?php
/* Ya no voy a usar este script ejecutado por un CRON, ya que me decidí por un evento programado en la base de datos. 

El CRON lo probé y funciona correctamente. El CRON se programa para que se ejecute cada 20 minutos así /usr/local/bin/php /home/listosof/public_html/apis/Limpiar_Sesiones_Inactivas.php

Decidí usar los eventos programados de MySql, para no sobrecargar el sistema operativo con un proceso que puede hacerlo la base de datos.

El comando para crearlo es este:
CREATE EVENT limpia_sesiones_inactivas
  ON SCHEDULE EVERY 10 MINUTE
  DO
    DELETE FROM sesiones_erp
    WHERE ultima_actividad < NOW() - INTERVAL 20 MINUTE;

Ver cómo está definido actualmente
SHOW CREATE EVENT limpia_sesiones_inactivas

Editar el evento
ALTER EVENT limpia_sesiones_inactivas
  ON SCHEDULE
    EVERY 10 MINUTE
  DO
    DELETE FROM sesiones_erp
    WHERE ultima_actividad < NOW() - INTERVAL 20 MINUTE;
*/

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acceso prohibido');
}

require("../shared/Conectar_BD.php");
date_default_timezone_set("America/Guayaquil");

// Conexión y comprobación de errores
$mysqli = Conectar_BD();
if ($mysqli->connect_error) {
    error_log("Cron limpia_sesiones: error de conexión – " . $mysqli->connect_error);
    exit(1);
}

// Definir límite de inactividad
//$limite = date("Y-m-d H:i:s", strtotime("-15 minutes"));
$limite = date("Y-m-d H:i:s", strtotime("-1 minutes"));
$sql    = "DELETE FROM sesiones_erp WHERE ultima_actividad < '$limite'";

// Ejecutar y verificar resultado
if ($mysqli->query($sql) === false) {
    error_log("Cron limpia_sesiones: fallo en DELETE – " . $mysqli->error);
    exit(1);
}

// Registrar cuántas filas afectó
error_log("Sesiones inactivas eliminadas: " . $mysqli->affected_rows);

$mysqli->close();
exit(0);
