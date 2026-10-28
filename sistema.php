
<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}
include('conexion.php');
$rol = $_SESSION['rol'];
$rolNormalizado = strtolower(trim($rol));
$rolNormalizado = strtr($rolNormalizado, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
$rolNormalizado = trim(preg_replace('/[^a-z0-9]+/', ' ', $rolNormalizado));
$mensaje = "";
$carrito = $_SESSION['carrito'] ?? [];

if (!is_array($carrito)) {
    $carrito = [];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agregar') {
        $idProducto = filter_input(INPUT_POST, 'id_producto', FILTER_VALIDATE_INT);
        $cantidad = filter_input(INPUT_POST, 'cantidad', FILTER_VALIDATE_INT);

        if ($idProducto && $cantidad && $cantidad > 0) {
            $stmtProd = $conexion->prepare("SELECT stock FROM productos WHERE id_producto = ?");
            $stmtProd->bind_param("i", $idProducto);
            $stmtProd->execute();
            $producto = $stmtProd->get_result()->fetch_assoc();
            $cantidadCarrito = ($carrito[$idProducto] ?? 0) + $cantidad;

            if ($producto && $cantidadCarrito <= (int) $producto['stock']) {
                $carrito[$idProducto] = $cantidadCarrito;
                $_SESSION['carrito'] = $carrito;
                $mensaje = "Producto agregado al carrito.";
            } else {
                $mensaje = "No hay stock suficiente para esa cantidad.";
            }
        } else {
            $mensaje = "Selecciona una cantidad válida.";
        }
    } elseif ($accion === 'quitar') {
        $idProducto = filter_input(INPUT_POST, 'id_producto', FILTER_VALIDATE_INT);
        if ($idProducto && isset($carrito[$idProducto])) {
            unset($carrito[$idProducto]);
            $_SESSION['carrito'] = $carrito;
            $mensaje = "Producto quitado del carrito.";
        }
    } elseif ($accion === 'finalizar') {
        if (!$carrito) {
            $mensaje = "Agrega productos antes de finalizar la compra.";
        } else {
            try {
                $conexion->begin_transaction();
                $productosCarrito = [];
                $total = 0;
                $stmtProd = $conexion->prepare("SELECT id_producto, descripcion, precio, stock FROM productos WHERE id_producto = ? FOR UPDATE");

                foreach ($carrito as $idProducto => $cantidad) {
                    $idProducto = (int) $idProducto;
                    $cantidad = (int) $cantidad;
                    $stmtProd->bind_param("i", $idProducto);
                    $stmtProd->execute();
                    $producto = $stmtProd->get_result()->fetch_assoc();

                    if (!$producto || $cantidad < 1 || (int) $producto['stock'] < $cantidad) {
                        throw new RuntimeException("Stock insuficiente para completar la compra. Revisa el carrito.");
                    }

                    $producto['cantidad'] = $cantidad;
                    $producto['subtotal'] = (float) $producto['precio'] * $cantidad;
                    $productosCarrito[] = $producto;
                    $total += $producto['subtotal'];
                }

                $stmtVenta = $conexion->prepare("INSERT INTO ventas (id_usuario, id_sucursal, total) VALUES (?, ?, ?)");
                $stmtVenta->bind_param("iid", $_SESSION['usuario_id'], $_SESSION['id_sucursal'], $total);
                $stmtVenta->execute();
                $idVenta = $stmtVenta->insert_id;

                $stmtDetalle = $conexion->prepare("INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, subtotal) VALUES (?, ?, ?, ?)");
                $stmtUpdate = $conexion->prepare("UPDATE productos SET stock = stock - ? WHERE id_producto = ? AND stock >= ?");

                foreach ($productosCarrito as $producto) {
                    $idProducto = (int) $producto['id_producto'];
                    $cantidad = (int) $producto['cantidad'];
                    $subtotal = (float) $producto['subtotal'];
                    $stmtDetalle->bind_param("iiid", $idVenta, $idProducto, $cantidad, $subtotal);
                    $stmtDetalle->execute();
                    $stmtUpdate->bind_param("iii", $cantidad, $idProducto, $cantidad);
                    $stmtUpdate->execute();

                    if ($stmtUpdate->affected_rows !== 1) {
                        throw new RuntimeException("No se pudo actualizar el stock. La compra no fue registrada.");
                    }
                }

                $conexion->commit();
                $_SESSION['carrito'] = [];
                $carrito = [];
                $mensaje = "Compra finalizada correctamente. Total: $" . number_format($total, 2);
            } catch (Throwable $error) {
                try {
                    $conexion->rollback();
                } catch (Throwable $rollbackError) {
                    error_log("No se pudo revertir la compra: " . $rollbackError->getMessage());
                }

                error_log("Error al finalizar la compra: " . $error->getMessage());
                $mensaje = $error instanceof RuntimeException
                    ? $error->getMessage()
                    : "Error al guardar la compra: " . $error->getMessage();
            }
        }
    }
}

$resProductos = $conexion->query("SELECT id_producto, codigo_barra, descripcion, precio, stock FROM productos ORDER BY descripcion");
$productos = $resProductos->fetch_all(MYSQLI_ASSOC);
$catalogo = [];
foreach ($productos as $producto) {
    $catalogo[(int) $producto['id_producto']] = $producto;
}
$totalCarrito = 0;
foreach ($carrito as $idProducto => $cantidad) {
    if (isset($catalogo[$idProducto])) {
        $totalCarrito += (float) $catalogo[$idProducto]['precio'] * (int) $cantidad;
    }
}
$verVentasSucursal = in_array($rolNormalizado, ['jefe de sucursal', 'jefe sucursal', 'jefe'], true);
$verTodasLasVentas = in_array($rolNormalizado, ['gerencia', 'gerente', 'admin', 'administrador'], true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sistema - Farmacias del Norte</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="estilos.css">
</head>
<body>
    <header>
        <a class="brand" href="sistema.php" aria-label="Farmacias del Norte, inicio">
            <span class="brand-mark" aria-hidden="true">+</span>
            <span><strong>Farmacias del Norte</strong><small>Tu salud, nuestra prioridad</small></span>
        </a>
        <div class="account-bar">
            <div class="account-details">
                <strong><?php echo htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8'); ?></strong>
                <span><?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <a class="logout-link" href="logout.php">Cerrar sesión</a>
        </div>
    </header>

    <div class="container">
        <?php if ($mensaje): ?>
            <div class="notice" role="status"><?php echo htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <section class="catalog-section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Cuidado para cada día</p>
                    <h2>Productos de farmacia</h2>
                    <p class="section-description">Encuentra lo que necesitas y agrégalo a tu carrito.</p>
                </div>
                <a class="cart-link" href="#carrito"><span class="cart-icon" aria-hidden="true">🛒</span> Ver carrito <span class="cart-badge"><?php echo array_sum($carrito); ?></span></a>
            </div>
            <div class="product-grid">
                <?php foreach ($productos as $producto): ?>
                    <?php
                    $imagenes = [
                        'https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?auto=format&fit=crop&w=720&q=80',
                        'https://images.unsplash.com/photo-1559757175-0eb30cd8c063?auto=format&fit=crop&w=720&q=80',
                        'https://images.unsplash.com/photo-1576602976047-174e57a47881?auto=format&fit=crop&w=720&q=80',
                        'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?auto=format&fit=crop&w=720&q=80'
                    ];
                    $imagen = $imagenes[((int) $producto['id_producto'] - 1) % count($imagenes)];
                    ?>
                    <article class="product-card">
                        <div class="product-image">
                            <img src="<?php echo htmlspecialchars($imagen, ENT_QUOTES, 'UTF-8'); ?>" alt="Imagen de <?php echo htmlspecialchars($producto['descripcion'], ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
                            <span class="image-label">Farmacia</span>
                        </div>
                        <div class="product-info">
                            <p class="product-code">Código <?php echo htmlspecialchars($producto['codigo_barra'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <h3><?php echo htmlspecialchars($producto['descripcion'], ENT_QUOTES, 'UTF-8'); ?></h3>
                            <div class="product-meta">
                                <strong>$<?php echo number_format((float) $producto['precio'], 2); ?></strong>
                                <span class="stock-label <?php echo (int) $producto['stock'] < 1 ? 'out-of-stock' : ''; ?>"><?php echo (int) $producto['stock'] > 0 ? 'Disponible' : 'Agotado'; ?></span>
                            </div>
                            <form method="POST" class="add-form">
                                <input type="hidden" name="accion" value="agregar">
                                <input type="hidden" name="id_producto" value="<?php echo (int) $producto['id_producto']; ?>">
                                <label class="visually-hidden" for="cantidad-<?php echo (int) $producto['id_producto']; ?>">Cantidad</label>
                                <input id="cantidad-<?php echo (int) $producto['id_producto']; ?>" type="number" name="cantidad" min="1" max="<?php echo (int) $producto['stock']; ?>" value="1" required <?php echo (int) $producto['stock'] < 1 ? 'disabled' : ''; ?>>
                                <button type="submit" <?php echo (int) $producto['stock'] < 1 ? 'disabled' : ''; ?>>Agregar al carrito</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card cart-section" id="carrito">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Tu selección</p>
                    <h2>Carrito de compra</h2>
                </div>
                <span class="cart-count"><?php echo array_sum($carrito); ?> artículos</span>
            </div>
            <?php if ($carrito): ?>
                <div class="cart-items">
                    <?php foreach ($carrito as $idProducto => $cantidad): ?>
                        <?php if (isset($catalogo[$idProducto])): ?>
                            <?php $producto = $catalogo[$idProducto]; ?>
                            <div class="cart-row">
                                <div>
                                    <h3><?php echo htmlspecialchars($producto['descripcion'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                    <p><?php echo (int) $cantidad; ?> × $<?php echo number_format((float) $producto['precio'], 2); ?></p>
                                </div>
                                <strong>$<?php echo number_format((float) $producto['precio'] * (int) $cantidad, 2); ?></strong>
                                <form method="POST">
                                    <input type="hidden" name="accion" value="quitar">
                                    <input type="hidden" name="id_producto" value="<?php echo (int) $idProducto; ?>">
                                    <button class="remove-button" type="submit" aria-label="Quitar <?php echo htmlspecialchars($producto['descripcion'], ENT_QUOTES, 'UTF-8'); ?>">Quitar</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div class="cart-total"><span>Total</span><strong>$<?php echo number_format($totalCarrito, 2); ?></strong></div>
                <form method="POST">
                    <input type="hidden" name="accion" value="finalizar">
                    <button class="checkout-button" type="submit">Finalizar compra</button>
                </form>
            <?php else: ?>
                <p class="empty-cart">Aún no agregaste productos.</p>
            <?php endif; ?>
        </section>

        <!-- Consulta General de Stock -->
        <div class="card">
            <h2>Inventario de Productos</h2>
            <table>
                <thead>
                    <tr><th>Código</th><th>Descripción</th><th>Precio</th><th>Stock</th></tr>
                </thead>
                <tbody>
                    <?php
                    $res = $conexion->query("SELECT * FROM productos ORDER BY descripcion");
                    while ($r = $res->fetch_assoc()) {
                        echo "<tr><td>" . htmlspecialchars($r['codigo_barra'], ENT_QUOTES, 'UTF-8') . "</td><td>" . htmlspecialchars($r['descripcion'], ENT_QUOTES, 'UTF-8') . "</td><td>$" . number_format((float) $r['precio'], 2) . "</td><td>" . (int) $r['stock'] . "</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <?php if ($verTodasLasVentas || $verVentasSucursal): ?>
        <div class="card">
            <h2><?php echo $verVentasSucursal ? 'Ventas de esta sucursal' : ($rolNormalizado === 'gerencia' || $rolNormalizado === 'gerente' ? 'Ventas de todas las sucursales' : 'Ventas finalizadas'); ?></h2>
            <table>
                <thead>
                    <tr><th>ID Venta</th><th>Fecha</th><th>Sucursal</th><th>Total</th></tr>
                </thead>
                <tbody>
                    <?php
                    if ($verTodasLasVentas) {
                        $resV = $conexion->query("SELECT v.id_venta, v.fecha, s.nombre AS sucursal, v.total FROM ventas v JOIN sucursales s ON v.id_sucursal = s.id_sucursal ORDER BY v.fecha DESC");
                    } else {
                        $stmtVentas = $conexion->prepare("SELECT v.id_venta, v.fecha, s.nombre AS sucursal, v.total FROM ventas v JOIN sucursales s ON v.id_sucursal = s.id_sucursal WHERE v.id_sucursal = ? ORDER BY v.fecha DESC");
                        $stmtVentas->bind_param("i", $_SESSION['id_sucursal']);
                        $stmtVentas->execute();
                        $resV = $stmtVentas->get_result();
                    }
                    while ($v = $resV->fetch_assoc()) {
                        echo "<tr><td>#" . (int) $v['id_venta'] . "</td><td>" . htmlspecialchars($v['fecha'], ENT_QUOTES, 'UTF-8') . "</td><td>" . htmlspecialchars($v['sucursal'], ENT_QUOTES, 'UTF-8') . "</td><td>$" . number_format((float) $v['total'], 2) . "</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>

