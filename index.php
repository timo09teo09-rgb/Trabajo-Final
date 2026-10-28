
<?php
session_start();
include('conexion.php');
$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $usuario = $_POST['usuario'];
    $clave   = $_POST['clave'];

    $stmt = $conexion->prepare("SELECT id_usuario, nombre, rol, id_sucursal FROM usuarios WHERE usuario = ? AND clave = ?");
    $stmt->bind_param("ss", $usuario, $clave);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $_SESSION['usuario_id']  = $row['id_usuario'];
        $_SESSION['nombre']      = $row['nombre'];
        $_SESSION['rol']         = $row['rol'];
        $_SESSION['id_sucursal'] = $row['id_sucursal'];
        header("Location: sistema.php");
        exit();
    } else {
        $error = "Credenciales inválidas.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso - Farmacias del Norte</title>
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
    <div class="login-box card">
        <h2>Iniciar Sesión</h2>
        <?php if ($error) echo "<p style='color:#ef4444;'>$error</p>"; ?>
        <form method="POST">
            <label>Usuario:</label>
            <input type="text" name="usuario" required>
            <label>Contraseña:</label>
            <input type="password" name="clave" required>
            <button type="submit">Ingresar</button>
        </form>
    </div>
</body>
</html>