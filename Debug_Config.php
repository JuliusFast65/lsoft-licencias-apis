<?php
// Si no se ha definido DEBUG_MODE antes, se asigna un valor por defecto (false)
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

// Función para escribir mensajes de depuración
function log_debug($mensaje) {
    if (DEBUG_MODE) {
        error_log("[DEBUG] " . $mensaje);
    }
}
