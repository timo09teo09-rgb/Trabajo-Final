
<?php
$conexion = new mysqli("localhost", "root", "", "farmacia_db");
if ($conexion->connect_error) {
    die("Error en la conexión: " . $conexion->connect_error);
}
$conexion->set_charset("utf8mb4");
?>
