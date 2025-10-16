<?php
date_default_timezone_set("America/Guayaquil");
require("../shared/Conectar_BD.php");
require_once 'Validar_Firma.php';

$input = validarPeticion();
$ping_token = $input["ping_token"] ?? '';

$mysqli = Conectar_BD();

if (!$ping_token) {
    http_response_code(400);
    echo json_encode([
        "Fin" => "Error",
        "Mensaje" => "Token no pasado"
    ]);
    exit;
}

$sql = "DELETE FROM sesiones_erp WHERE ping_token = '$ping_token'";
$mysqli->query($sql);

echo json_encode([
    "Fin" => "OK",
    "Mensaje" => "Todas las sesiones cerradas para el token"
]);
?>
