<?php
/**
 * Obtiene el nombre de la última actualización disponible
 *
 * @param mysqli $conn      Conexión a la base de datos
 * @param string $Sistema   Identificador del sistema
 * @return string           Nombre del archivo .zip
 * @throws Exception        En caso de error de consulta
 */
function ObtNombreUltAct3($conn, $Sistema) {
    
    // Abreviatura del sistema para la consulta
    $sAbrev = substr($Sistema, 0, 1);

    // Construyo la consulta
    $sql = "
        SELECT Version AS Ultima_Version
        FROM Versiones
        WHERE Sistema = '{$sAbrev}'
          AND En_FTP
        ORDER BY Version DESC
        LIMIT 1
    ";

    // Lanzo excepción si falla la consulta
    if (! $res = $conn->query($sql)) {
        throw new Exception("Error de Base de Datos: " . $conn->error);
    }

    $Ultima_Act = '';

    // Si hay exactamente 1 fila, la uso
    if ($res->num_rows === 1) {
        $version = $res->fetch_assoc();
        if (! $version) {
            throw new Exception("Error al leer resultado: " . $conn->error);
        }

        // Formo el nombre del ZIP según el sistema
        if ($Sistema === 'FSoft') {
            $Ultima_Act = "FSoftUp {$version['Ultima_Version']}.zip";
        } else {
            $Ultima_Act = "FsoftNew{$version['Ultima_Version']}.zip";
        }
    }

    return $Ultima_Act;
}