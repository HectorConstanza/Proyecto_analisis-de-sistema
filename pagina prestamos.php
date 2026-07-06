<?php
/**
 * SISTEMA INTEGRAL DE GESTIÓN CREDITICIA Y FISCAL - BATIANALISIS V5.1 CORE
 * ARQUITECTURA MODULAR UNIFICADA - SATELLITE COMPLIANT (ESIT / UCA)
 */
session_start();
date_default_timezone_set('America/El_Salvador');

// ==========================================
// FASE 1 & 8: CONEXIÓN, ESQUEMA Y MIGRACIÓN
// ==========================================
try {
    $db = new PDO('sqlite:database.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Habilitar claves foráneas en SQLite
    $db->exec("PRAGMA foreign_keys = ON;");

    // 1. Tabla de Usuarios (Fase 1, 2)
    $db->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        nombre TEXT NOT NULL,
        carnet TEXT UNIQUE NOT NULL,
        rol TEXT NOT NULL, -- 'Analista' o 'Asociado'
        ingresos_mensuales REAL DEFAULT 0.0,
        gastos_mensuales REAL DEFAULT 0.0,
        score_crediticio INTEGER DEFAULT 700, -- Historial crediticio simulado
        activo INTEGER DEFAULT 1 -- Eliminación lógica
    )");

    // 2. Tabla de Solicitudes (Fase 3, 4)
    $db->exec("CREATE TABLE IF NOT EXISTS solicitudes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER NOT NULL,
        monto_solicitado REAL NOT NULL,
        plazo_meses INTEGER NOT NULL,
        documento_adjunto TEXT, 
        capacidad_pago REAL,
        relacion_cuota_ingreso REAL,
        estado TEXT DEFAULT 'Pendiente', 
        observaciones TEXT,
        fecha_creacion TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
    )");

    // 3. Tabla de Préstamos Activos (Fase 5)
    $db->exec("CREATE TABLE IF NOT EXISTS prestamos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        solicitud_id INTEGER NOT NULL,
        monto_aprobado REAL NOT NULL,
        saldo_actual REAL NOT NULL,
        tasa_interes REAL DEFAULT 0.12, 
        fecha_inicio TEXT NOT NULL,
        estado TEXT DEFAULT 'Activo', 
        FOREIGN KEY (solicitud_id) REFERENCES solicitudes(id)
    )");

    // 4. Tabla de Pagos / Amortizaciones (Fase 5)
    $db->exec("CREATE TABLE IF NOT EXISTS pagos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        prestamo_id INTEGER NOT NULL,
        monto_pago REAL NOT NULL,
        interes_pagado REAL NOT NULL,
        capital_pagado REAL NOT NULL,
        recargo_mora REAL DEFAULT 0.0,
        fecha_pago TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (prestamo_id) REFERENCES prestamos(id)
    )");

    // 5. Tabla de Facturación Electrónica DTE (Fase 6)
    $db->exec("CREATE TABLE IF NOT EXISTS facturas_dte (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pago_id INTEGER NOT NULL,
        sello_recepcion TEXT UNIQUE NOT NULL,
        tipo_documento TEXT DEFAULT 'Comprobante de Crédito Electrónico',
        monto_afecto REAL NOT NULL,
        iva REAL NOT NULL,
        estado_dte TEXT DEFAULT 'Emitido', 
        fecha_emision TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pago_id) REFERENCES pagos(id)
    )");

    // 6. Tabla de Notificaciones Internas (Fase 4)
    $db->exec("CREATE TABLE IF NOT EXISTS notificaciones (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario_id INTEGER NOT NULL,
        mensaje TEXT NOT NULL,
        leido INTEGER DEFAULT 0,
        fecha TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // 7. Tabla de Bitácora y Auditoría de Operaciones (Fase 8)
    $db->exec("CREATE TABLE IF NOT EXISTS bitacora (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        usuario TEXT NOT NULL,
        accion TEXT NOT NULL,
        detalles TEXT,
        fecha TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // Semilla de datos si está vacío
    $checkVacido = $db->query("SELECT COUNT(*) as total FROM usuarios")->fetch();
    if ($checkVacido['total'] == 0) {
        $db->exec("INSERT INTO usuarios (nombre, carnet, rol, ingresos_mensuales, gastos_mensuales, score_crediticio, activo) VALUES 
            ('Héctor Ernesto Argueta Constanza', '00012424', 'Analista', 2500.00, 800.00, 780, 1),
            ('Leonel Alexander Cañas Rodríguez', '00145424', 'Analista', 2200.00, 700.00, 750, 1),
            ('Asociado Ejemplo de Pruebas', '00001111', 'Asociado', 1200.00, 400.00, 650, 1)
        ");
    }

} catch (PDOException $e) {
    die("Error crítico de inicialización de Base de Datos: " . $e->getMessage());
}

// ==========================================
// FUNCIONES AUXILIARES DE AUDITORÍA Y CÁLCULO
// ==========================================
function registrarBitacora($db, $usuario, $accion, $detalles) {
    $stmt = $db->prepare("INSERT INTO bitacora (usuario, accion, detalles) VALUES (?, ?, ?)");
    $stmt->execute([$usuario, $accion, $detalles]);
}

function crearNotificacion($db, $usuario_id, $mensaje) {
    $stmt = $db->prepare("INSERT INTO notificaciones (usuario_id, mensaje) VALUES (?, ?)");
    $stmt->execute([$usuario_id, $mensaje]);
}

function calcularAmortizacionFrancesa($monto, $meses, $tasaAnual) {
    $tasaMensual = $tasaAnual / 12;
    if ($tasaMensual == 0) return [];
    $cuota = $monto * ($tasaMensual * pow(1 + $tasaMensual, $meses)) / (pow(1 + $tasaMensual, $meses) - 1);
    
    $tabla = []; $saldo = $monto;
    for ($i = 1; $i <= $meses; $i++) {
        $interes = $saldo * $tasaMensual;
        $capital = $cuota - $interes;
        $saldo -= $capital;
        $tabla[] = [
            "mes" => $i,
            "cuota" => round($cuota, 2),
            "interes" => round($interes, 2),
            "capital" => round($capital, 2),
            "saldo" => round(max(0, $saldo), 2)
        ];
    }
    return $tabla;
}

// ==========================================
// CONTROLADOR DE ACCIONES / PETICIONES POST
// ==========================================
$mensaje = ''; $tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $operacion = $_POST['operacion'] ?? '';
    
    try {
        // --- AUTENTICACIÓN PASO 1: VALIDACIÓN DE CARNET (Fase 1) ---
        if ($operacion === 'login') {
            $carnet = trim($_POST['carnet']);
            $stmt = $db->prepare("SELECT * FROM usuarios WHERE carnet = ? AND activo = 1");
            $stmt->execute([$carnet]);
            $user = $stmt->fetch();
            
            if ($user) {
                // No se abre la sesión completa todavía: se deja pendiente de verificación 2FA
                $_SESSION['pre2fa_id'] = $user['id'];
                $_SESSION['pre2fa_nombre'] = $user['nombre'];
                $_SESSION['pre2fa_carnet'] = $user['carnet'];
                $_SESSION['pre2fa_rol'] = $user['rol'];
                $codigo2fa = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $_SESSION['2fa_codigo'] = $codigo2fa;
                $_SESSION['2fa_generado'] = time();
                registrarBitacora($db, $user['nombre'], "Login Fase 1 (Carnet)", "Carnet validado, código de verificación 2FA generado y pendiente de confirmación.");
                header("Location: index.php"); exit();
            } else {
                $mensaje = "Credenciales o carnet inválidos."; $tipo_mensaje = "error";
            }
        }

        // --- AUTENTICACIÓN PASO 2: VERIFICACIÓN 2FA (SIMULADA) ---
        if ($operacion === 'verificar_2fa') {
            if (!isset($_SESSION['pre2fa_id'])) {
                $mensaje = "La sesión de verificación expiró. Inicia sesión nuevamente."; $tipo_mensaje = "error";
            } else {
                $codigo_ingresado = trim($_POST['codigo_2fa'] ?? '');
                $expirado = (time() - ($_SESSION['2fa_generado'] ?? 0)) > 300; // 5 minutos de validez

                if ($expirado) {
                    $mensaje = "El código de verificación expiró. Solicita uno nuevo."; $tipo_mensaje = "error";
                } elseif ($codigo_ingresado === ($_SESSION['2fa_codigo'] ?? '')) {
                    $_SESSION['user_id'] = $_SESSION['pre2fa_id'];
                    $_SESSION['nombre'] = $_SESSION['pre2fa_nombre'];
                    $_SESSION['carnet'] = $_SESSION['pre2fa_carnet'];
                    $_SESSION['rol'] = $_SESSION['pre2fa_rol'];

                    unset($_SESSION['pre2fa_id'], $_SESSION['pre2fa_nombre'], $_SESSION['pre2fa_carnet'], $_SESSION['pre2fa_rol'], $_SESSION['2fa_codigo'], $_SESSION['2fa_generado']);

                    registrarBitacora($db, $_SESSION['nombre'], "Login Fase 2 (2FA Verificado)", "Segundo factor de autenticación confirmado con éxito.");
                    header("Location: index.php"); exit();
                } else {
                    registrarBitacora($db, $_SESSION['pre2fa_nombre'] ?? 'Desconocido', "Intento 2FA Fallido", "Código de verificación incorrecto.");
                    $mensaje = "Código de verificación incorrecto. Inténtalo de nuevo."; $tipo_mensaje = "error";
                }
            }
        }

        // --- REENVÍO DE CÓDIGO 2FA (SIMULADO) ---
        if ($operacion === 'reenviar_2fa') {
            if (isset($_SESSION['pre2fa_id'])) {
                $codigo2fa = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $_SESSION['2fa_codigo'] = $codigo2fa;
                $_SESSION['2fa_generado'] = time();
                registrarBitacora($db, $_SESSION['pre2fa_nombre'], "Reenvío Código 2FA", "Se generó un nuevo código de verificación simulado.");
                $mensaje = "Se generó un nuevo código de verificación."; $tipo_mensaje = "success";
            }
        }
        
        // --- AUTO-REGISTRO PÚBLICO DE ASOCIADOS ---
        if ($operacion === 'autoregistro') {
            $nombre = trim($_POST['nombre']);
            $carnet = trim($_POST['carnet']);
            $ingresos = floatval($_POST['ingresos']);
            $gastos = floatval($_POST['gastos']);
            $score_inicial = rand(600, 750); // Buró simulado aleatorio para nuevos ingresos

            // Verificar duplicados
            $chk = $db->prepare("SELECT COUNT(*) as total FROM usuarios WHERE carnet = ?");
            $chk->execute([$carnet]);
            if ($chk->fetch()['total'] > 0) {
                throw new Exception("El número de carnet ya se encuentra registrado en el sistema.");
            }

            $stmt = $db->prepare("INSERT INTO usuarios (nombre, carnet, rol, ingresos_mensuales, gastos_mensuales, score_crediticio, activo) VALUES (?, ?, 'Asociado', ?, ?, ?, 1)");
            $stmt->execute([$nombre, $carnet, $ingresos, $gastos, $score_inicial]);
            
            registrarBitacora($db, $nombre, "Auto-registro exitoso", "Cuenta de Asociado creada autónomamente vía portal.");
            $mensaje = "¡Cuenta creada con éxito! Ya puedes iniciar sesión con tu carnet."; $tipo_mensaje = "success";
        }
        
        // --- CONTROL DE SEGURIDAD SESIÓN AUTENTICADA ---
        if (isset($_SESSION['user_id'])) {
            $operador = $_SESSION['nombre'];
            
            switch ($operacion) {
                // --- CRUD USUARIOS POR ANALISTA (Fase 2) ---
                case 'guardar_usuario':
                    if ($_SESSION['rol'] !== 'Analista') throw new Exception("Privilegios insuficientes.");
                    $id = $_POST['id'] ?? '';
                    if (empty($id)) {
                        $stmt = $db->prepare("INSERT INTO usuarios (nombre, carnet, rol, ingresos_mensuales, gastos_mensuales, score_crediticio) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$_POST['nombre'], $_POST['carnet'], $_POST['rol'], $_POST['ingresos'], $_POST['gastos'], $_POST['score']]);
                        registrarBitacora($db, $operador, "Crear Usuario", "Analista registró a: " . $_POST['nombre']);
                    } else {
                        $stmt = $db->prepare("UPDATE usuarios SET nombre=?, carnet=?, rol=?, ingresos_mensuales=?, gastos_mensuales=?, score_crediticio=? WHERE id=?");
                        $stmt->execute([$_POST['nombre'], $_POST['carnet'], $_POST['rol'], $_POST['ingresos'], $_POST['gastos'], $_POST['score'], $id]);
                        registrarBitacora($db, $operador, "Modificar Usuario", "ID modificado: " . $id);
                    }
                    $mensaje = "Registro de usuario procesado con éxito."; $tipo_mensaje = "success";
                    break;

                case 'eliminar_logico':
                    if ($_SESSION['rol'] !== 'Analista') throw new Exception("Privilegios insuficientes.");
                    $stmt = $db->prepare("UPDATE usuarios SET activo = 0 WHERE id = ?");
                    $stmt->execute([$_POST['id']]);
                    registrarBitacora($db, $operador, "Eliminación Lógica", "ID de usuario desactivado: " . $_POST['id']);
                    $mensaje = "Usuario dado de baja lógicamente."; $tipo_mensaje = "success";
                    break;

                // --- SOLICITUDES Y EVALUACIÓN CREDITICIA (Fase 3 & 4) ---
                case 'crear_solicitud':
                    $monto = floatval($_POST['monto']);
                    $plazo = intval($_POST['plazo']);
                    $u_id = $_SESSION['user_id'];
                    
                    $stmtU = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
                    $stmtU->execute([$u_id]);
                    $asoc = $stmtU->fetch();

                    $chkMora = $db->prepare("SELECT COUNT(*) as en_mora FROM prestamos p JOIN solicitudes s ON p.solicitud_id = s.id WHERE s.usuario_id = ? AND p.estado = 'Mora'");
                    $chkMora->execute([$u_id]);
                    if ($chkMora->fetch()['en_mora'] > 0) {
                        throw new Exception("Denegado: Presenta saldos en mora vigentes.");
                    }

                    $tablaSim = calcularAmortizacionFrancesa($monto, $plazo, 0.12);
                    $cuotaEstimada = !empty($tablaSim) ? $tablaSim[0]['cuota'] : 0;
                    
                    $ingresoNeto = $asoc['ingresos_mensuales'] - $asoc['gastos_mensuales'];
                    $capacidadPago = $ingresoNeto - $cuotaEstimada;
                    $relacionCuotaIngreso = ($cuotaEstimada / ($asoc['ingresos_mensuales'] ?: 1)) * 100;

                    $docAdjunto = "Firma_Digital_Hash_" . md5(time() . $u_id);
                    if (isset($_FILES['documento']) && $_FILES['documento']['size'] > 0) {
                        $docAdjunto = "data:application/pdf;base64," . base64_encode(file_get_contents($_FILES['documento']['tmp_name']));
                    }

                    $stmt = $db->prepare("INSERT INTO solicitudes (usuario_id, monto_solicitado, plazo_meses, documento_adjunto, capacidad_pago, relacion_cuota_ingreso, estado) VALUES (?, ?, ?, ?, ?, ?, 'Pendiente')");
                    $stmt->execute([$u_id, $monto, $plazo, $docAdjunto, $capacidadPago, $relacionCuotaIngreso]);
                    
                    registrarBitacora($db, $operador, "Nueva Solicitud", "Monto: $" . $monto);
                    $mensaje = "Solicitud enviada a la cola de análisis del comité."; $tipo_mensaje = "success";
                    break;

                case 'evaluar_solicitud':
                    if ($_SESSION['rol'] !== 'Analista') throw new Exception("Privilegios insuficientes.");
                    $sol_id = $_POST['solicitud_id'];
                    $nuevo_estado = $_POST['estado_evaluacion']; 
                    $obs = trim($_POST['observaciones']);

                    $db->beginTransaction();
                    
                    $stmtS = $db->prepare("UPDATE solicitudes SET estado = ?, observaciones = ? WHERE id = ?");
                    $stmtS->execute([$nuevo_estado, $obs, $sol_id]);

                    $getSol = $db->prepare("SELECT * FROM solicitudes WHERE id = ?");
                    $getSol->execute([$sol_id]);
                    $solData = $getSol->fetch();

                    crearNotificacion($db, $solData['usuario_id'], "Tu solicitud #SOL-".$sol_id." fue cambiada a estado: ".$nuevo_estado);

                    if ($nuevo_estado === 'Aprobado') {
                        $insP = $db->prepare("INSERT INTO prestamos (solicitud_id, monto_aprobado, saldo_actual, fecha_inicio) VALUES (?, ?, ?, ?)");
                        $insP->execute([$sol_id, $solData['monto_solicitado'], $solData['monto_solicitado'], date('Y-m-d')]);
                    }

                    $db->commit();
                    registrarBitacora($db, $operador, "Evaluación de Crédito", "Solicitud #$sol_id dictaminada como $nuevo_estado");
                    $mensaje = "Dictamen ejecutado y consolidado en el core bancario."; $tipo_mensaje = "success";
                    break;

                // --- LIQUIDACIÓN DE PAGOS Y DTE (Fase 5 & 6) ---
                case 'procesar_pago':
                    if ($_SESSION['rol'] !== 'Analista') throw new Exception("Privilegios insuficientes.");
                    $p_id = $_POST['prestamo_id'];
                    $monto_abonado = floatval($_POST['monto_pago']);

                    $db->beginTransaction();

                    $stmtP = $db->prepare("SELECT * FROM prestamos WHERE id = ?");
                    $stmtP->execute([$p_id]);
                    $prestamo = $stmtP->fetch();

                    if (!$prestamo) throw new Exception("Préstamo inexistente.");

                    $interesMensual = round($prestamo['saldo_actual'] * ($prestamo['tasa_interes'] / 12), 2);
                    $moraRecargo = ($prestamo['estado'] === 'Mora') ? round($interesMensual * 0.10, 2) : 0.0;
                    
                    $capitalAmortizado = $monto_abonado - $interesMensual - $moraRecargo;
                    $nuevoSaldo = max(0, $prestamo['saldo_actual'] - $capitalAmortizado);

                    $insPago = $db->prepare("INSERT INTO pagos (prestamo_id, monto_pago, interes_pagado, capital_pagado, recargo_mora) VALUES (?, ?, ?, ?, ?)");
                    $insPago->execute([$p_id, $monto_abonado, $interesMensual, $capitalAmortizado, $moraRecargo]);
                    $pago_id_generado = $db->lastInsertId();

                    $nuevo_estado_p = ($nuevoSaldo <= 0) ? 'Cancelado' : 'Activo';
                    $upP = $db->prepare("UPDATE prestamos SET saldo_actual = ?, estado = ? WHERE id = ?");
                    $upP->execute([$nuevoSaldo, $nuevo_estado_p, $p_id]);

                    $selloDTE = "DTE-11-CCFE-" . date('YmdHis') . "-" . str_pad($pago_id_generado, 6, '0', STR_PAD_LEFT);
                    $ivaSimulado = round($interesMensual * 0.13, 2); 

                    $insDte = $db->prepare("INSERT INTO facturas_dte (pago_id, sello_recepcion, monto_afecto, iva) VALUES (?, ?, ?, ?)");
                    $insDte->execute([$pago_id_generado, $selloDTE, $monto_abonado, $ivaSimulado]);

                    $db->commit();
                    registrarBitacora($db, $operador, "Pago y DTE", "Abono aplicado al préstamo #$p_id.");
                    $mensaje = "Pago aplicado y DTE firmado por el Ministerio de Hacienda."; $tipo_mensaje = "success";
                    break;

                case 'anular_dte':
                    if ($_SESSION['rol'] !== 'Analista') throw new Exception("Privilegios insuficientes.");
                    $dte_id = $_POST['dte_id'];
                    $stmt = $db->prepare("UPDATE facturas_dte SET estado_dte = 'Anulado' WHERE id = ?");
                    $stmt->execute([$dte_id]);
                    registrarBitacora($db, $operador, "Anulación DTE", "ID DTE Invalidado: " . $dte_id);
                    $mensaje = "El documento electrónico ha sido invalidado."; $tipo_mensaje = "success";
                    break;
            }
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $mensaje = "Error: " . $e->getMessage(); $tipo_mensaje = "error";
    }
}

// Cierre de sesión manual
if (isset($_GET['logout'])) {
    session_destroy(); header("Location: index.php"); exit();
}

// ==========================================
// DESCARGA DE FACTURA DTE (Fase 6)
// ==========================================
if (isset($_GET['descargar_dte']) && isset($_SESSION['user_id']) && $_SESSION['rol'] === 'Analista') {
    $dte_id = intval($_GET['descargar_dte']);
    $stmtD = $db->prepare("SELECT d.*, p.monto_pago, p.interes_pagado, p.capital_pagado, p.recargo_mora, pr.id as prestamo_id, u.nombre as cliente, u.carnet
                            FROM facturas_dte d
                            JOIN pagos p ON d.pago_id = p.id
                            JOIN prestamos pr ON p.prestamo_id = pr.id
                            JOIN solicitudes s ON pr.solicitud_id = s.id
                            JOIN usuarios u ON s.usuario_id = u.id
                            WHERE d.id = ?");
    $stmtD->execute([$dte_id]);
    $factura = $stmtD->fetch();

    if ($factura) {
        registrarBitacora($db, $_SESSION['nombre'], "Descarga de Factura", "DTE #".$dte_id." descargado como comprobante.");

        $nombreArchivo = "Factura_DTE_" . $factura['id'] . ".html";
        header("Content-Type: text/html; charset=UTF-8");
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');

        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Factura DTE #' . htmlspecialchars($factura['id']) . '</title>
        <style>
            body{font-family: "JetBrains Mono", monospace; background:#0f172a; color:#e2e8f0; padding:40px;}
            .invoice{max-width:600px;margin:0 auto;background:#020617;border:1px solid #1e293b;border-radius:16px;padding:32px;}
            h1{color:#22d3ee;font-size:16px;text-transform:uppercase;letter-spacing:1px;margin:0 0 4px;}
            .sub{color:#64748b;font-size:11px;margin-bottom:24px;}
            table{width:100%;border-collapse:collapse;font-size:12px;}
            td{padding:8px 0;border-bottom:1px solid #1e293b;}
            td.label{color:#64748b;width:55%;}
            td.value{color:#fff;font-weight:bold;text-align:right;}
            .estado{display:inline-block;padding:4px 10px;border-radius:8px;font-size:10px;font-weight:bold;}
            .emitido{background:rgba(16,185,129,0.15);color:#34d399;}
            .anulado{background:rgba(244,63,94,0.15);color:#fb7185;}
            .footer{margin-top:24px;font-size:10px;color:#475569;text-align:center;}
        </style></head><body>
        <div class="invoice">
            <h1>' . htmlspecialchars($factura['tipo_documento']) . '</h1>
            <div class="sub">Comprobante fiscal emitido por Batianalisis Core v5.1</div>
            <table>
                <tr><td class="label">Sello de Recepción</td><td class="value">' . htmlspecialchars($factura['sello_recepcion']) . '</td></tr>
                <tr><td class="label">Fecha de Emisión</td><td class="value">' . htmlspecialchars($factura['fecha_emision']) . '</td></tr>
                <tr><td class="label">Cliente</td><td class="value">' . htmlspecialchars($factura['cliente']) . '</td></tr>
                <tr><td class="label">Carnet</td><td class="value">' . htmlspecialchars($factura['carnet']) . '</td></tr>
                <tr><td class="label">Préstamo Asociado</td><td class="value">#PR-' . htmlspecialchars($factura['prestamo_id']) . '</td></tr>
                <tr><td class="label">Monto Total del Abono</td><td class="value">$' . number_format($factura['monto_pago'], 2) . '</td></tr>
                <tr><td class="label">Interés Pagado</td><td class="value">$' . number_format($factura['interes_pagado'], 2) . '</td></tr>
                <tr><td class="label">Capital Pagado</td><td class="value">$' . number_format($factura['capital_pagado'], 2) . '</td></tr>
                <tr><td class="label">Recargo por Mora</td><td class="value">$' . number_format($factura['recargo_mora'], 2) . '</td></tr>
                <tr><td class="label">Monto Afecto</td><td class="value">$' . number_format($factura['monto_afecto'], 2) . '</td></tr>
                <tr><td class="label">IVA (13%)</td><td class="value">$' . number_format($factura['iva'], 2) . '</td></tr>
                <tr><td class="label">Estado del Documento</td><td class="value"><span class="estado ' . ($factura['estado_dte']==='Anulado'?'anulado':'emitido') . '">' . htmlspecialchars($factura['estado_dte']) . '</span></td></tr>
            </table>
            <div class="footer">Documento generado electrónicamente — Batianalisis Core v5.1<br>Este comprobante no requiere firma autógrafa.</div>
        </div>
        </body></html>';
        exit();
    } else {
        header("Location: index.php?sec=dte_historial"); exit();
    }
}

$is_logged = isset($_SESSION['user_id']);
$pendiente_2fa = !$is_logged && isset($_SESSION['pre2fa_id']);
$rol_sesion = $_SESSION['rol'] ?? '';
$sec = $_GET['sec'] ?? ($rol_sesion === 'Analista' ? 'kpis' : 'asoc_cuenta');
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ecosistema Modular Batianalisis Core v5.1</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="h-full text-slate-900 flex flex-col">

<?php if (!$is_logged): ?>
    <div class="min-h-full flex flex-col justify-center py-12 sm:px-6 lg:px-8 bg-slate-950 relative overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_var(--tw-gradient-stops))] from-cyan-900/20 via-transparent to-transparent"></div>
        
        <div class="sm:mx-auto w-full max-w-md relative z-10 text-center mb-6">
            <span class="inline-flex items-center justify-center p-3 bg-gradient-to-tr from-cyan-500 to-blue-600 rounded-2xl shadow-lg shadow-cyan-500/20 text-slate-950 font-black text-3xl tracking-tighter">BA</span>
            <h2 class="mt-4 text-2xl font-extrabold text-white tracking-tight">Batianalisis Core Ecosistema</h2>
            <p class="mt-1 text-sm text-slate-400">Control de Créditos y Emisión de DTE Corporativo</p>
        </div>

        <div class="sm:mx-auto w-full max-w-md relative z-10 px-4">
            <div class="bg-slate-900/80 border border-slate-800 backdrop-blur-md py-8 px-4 shadow-2xl rounded-3xl sm:px-10">
                
                <?php if ($mensaje): ?>
                    <div class="mb-4 p-3 <?= $tipo_mensaje==='success'?'bg-emerald-500/10 border-emerald-500/20 text-emerald-400':'bg-rose-500/10 border-rose-500/20 text-rose-400' ?> border rounded-xl text-xs font-mono flex items-center gap-2">
                        <i data-lucide="info" class="w-4 h-4"></i> <?= $mensaje ?>
                    </div>
                <?php endif; ?>

                <?php if ($pendiente_2fa): ?>
                    <div class="text-center mb-5">
                        <span class="inline-flex items-center justify-center p-3 bg-cyan-500/10 text-cyan-400 rounded-2xl mb-3"><i data-lucide="shield-check" class="w-6 h-6"></i></span>
                        <h3 class="text-white font-bold text-sm">Verificación en Dos Pasos</h3>
                        <p class="text-slate-400 text-xs mt-1">Hola <?= htmlspecialchars($_SESSION['pre2fa_nombre']) ?>, confirma tu identidad con el código de 6 dígitos.</p>
                    </div>

                    <div class="mb-4 p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-[11px] font-mono text-amber-300 flex items-start gap-2">
                        <i data-lucide="flask-conical" class="w-4 h-4 mt-0.5 flex-shrink-0"></i>
                        <span>Modo demostración (2FA simulado, sin envío real de SMS/correo). Tu código actual es: <strong class="text-amber-200 tracking-widest"><?= htmlspecialchars($_SESSION['2fa_codigo']) ?></strong></span>
                    </div>

                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="operacion" value="verificar_2fa">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-slate-400">Código de Verificación</label>
                            <div class="mt-1 relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i data-lucide="key-round" class="h-4 w-4 text-slate-500"></i>
                                </div>
                                <input type="text" name="codigo_2fa" required maxlength="6" inputmode="numeric" placeholder="000000" class="block w-full pl-10 pr-3 py-3 bg-slate-950/60 border border-slate-800 rounded-xl text-white font-mono tracking-[0.3em] text-center placeholder-slate-600 focus:outline-none focus:border-cyan-500 text-lg">
                            </div>
                        </div>
                        <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-slate-950 bg-gradient-to-r from-cyan-400 to-blue-500 hover:from-cyan-300 hover:to-blue-400 focus:outline-none transition-all">
                            Verificar y Entrar
                        </button>
                    </form>

                    <form method="POST" class="mt-3">
                        <input type="hidden" name="operacion" value="reenviar_2fa">
                        <button type="submit" class="w-full text-xs font-bold text-cyan-400 hover:text-cyan-300 py-2">Reenviar código</button>
                    </form>
                <?php else: ?>
                <div class="grid grid-cols-2 bg-slate-950 p-1 rounded-xl mb-6 text-xs font-bold font-mono text-center">
                    <button onclick="cambiarVistaForm('form-login', this)" class="tab-btn py-2 rounded-lg text-white bg-slate-800">Iniciar Sesión</button>
                    <button onclick="cambiarVistaForm('form-registro', this)" class="tab-btn py-2 rounded-lg text-slate-400 hover:text-white">Registrarse</button>
                </div>

                <form id="form-login" method="POST" class="space-y-6">
                    <input type="hidden" name="operacion" value="login">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-400">Carnet Identificador</label>
                        <div class="mt-1 relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i data-lucide="user-lock" class="h-4 w-4 text-slate-500"></i>
                            </div>
                            <input type="text" name="carnet" required placeholder="Ej. 00012424" class="block w-full pl-10 pr-3 py-3 bg-slate-950/60 border border-slate-800 rounded-xl text-white font-mono placeholder-slate-600 focus:outline-none focus:border-cyan-500 text-sm">
                        </div>
                    </div>
                    <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-slate-950 bg-gradient-to-r from-cyan-400 to-blue-500 hover:from-cyan-300 hover:to-blue-400 focus:outline-none transition-all">
                        Validar Firma y Entrar
                    </button>
                </form>

                <form id="form-registro" method="POST" class="space-y-4 hidden text-xs">
                    <input type="hidden" name="operacion" value="autoregistro">
                    <div>
                        <label class="block font-bold text-slate-400 mb-1">Nombre Completo</label>
                        <input type="text" name="nombre" required placeholder="Ej. Juan Pérez" class="w-full p-2.5 bg-slate-950/60 border border-slate-800 rounded-xl text-white focus:outline-none focus:border-cyan-500 text-sm">
                    </div>
                    <div>
                        <label class="block font-bold text-slate-400 mb-1">Carnet Institucional Único</label>
                        <input type="text" name="carnet" required placeholder="Ej. 00224426" class="w-full p-2.5 bg-slate-950/60 border border-slate-800 rounded-xl text-white font-mono focus:outline-none focus:border-cyan-500 text-sm">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-400 mb-1">Ingresos Mensuales ($)</label>
                            <input type="number" name="ingresos" step="0.01" required placeholder="1200.00" class="w-full p-2.5 bg-slate-950/60 border border-slate-800 rounded-xl text-white font-mono focus:outline-none focus:border-cyan-500 text-sm">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-400 mb-1">Gastos Mensuales ($)</label>
                            <input type="number" name="gastos" step="0.01" required placeholder="500.00" class="w-full p-2.5 bg-slate-950/60 border border-slate-800 rounded-xl text-white font-mono focus:outline-none focus:border-cyan-500 text-sm">
                        </div>
                    </div>
                    <button type="submit" class="w-full mt-2 flex justify-center py-3 px-4 border border-transparent rounded-xl shadow-md text-sm font-bold text-slate-950 bg-gradient-to-r from-emerald-400 to-teal-500 hover:from-emerald-300 hover:to-teal-400 focus:outline-none transition-all">
                        Crear Mi Cuenta de Asociado
                    </button>
                </form>
                
                <div class="mt-6 pt-4 border-t border-slate-800/60 text-center">
                    <p class="text-[10px] text-slate-500 font-mono">Credenciales de Análisis Administrativo: 00012424</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function cambiarVistaForm(formId, btn) {
            document.getElementById('form-login').classList.add('hidden');
            document.getElementById('form-registro').classList.add('hidden');
            document.getElementById(formId).classList.remove('hidden');
            
            document.querySelectorAll('.tab-btn').forEach(b => {
                b.classList.remove('bg-slate-800', 'text-white');
                b.classList.add('text-slate-400');
            });
            btn.classList.add('bg-slate-800', 'text-white');
            btn.classList.remove('text-slate-400');
        }
    </script>
<?php else: ?>

    <div class="flex h-full overflow-hidden">
        
        <aside class="hidden lg:flex lg:flex-shrink-0 w-72 bg-slate-900 border-r border-slate-800 flex-col p-6 justify-between text-slate-300">
            <div class="space-y-6">
                <div class="flex items-center gap-3">
                    <div class="bg-cyan-500 text-slate-950 p-2 rounded-xl font-black text-xl">BA</div>
                    <div>
                        <h1 class="font-bold text-sm text-white leading-none">Batianalisis</h1>
                        <span class="text-[10px] font-mono text-cyan-400">v5.1 Enterprise</span>
                    </div>
                </div>

                <nav class="space-y-1">
                    <?php if ($rol_sesion === 'Analista'): ?>
                        <div class="text-[10px] font-black tracking-wider text-slate-500 uppercase px-3 mb-2">Módulos Administrativos</div>
                        <a href="?sec=kpis" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-xs font-semibold tracking-wide <?= $sec==='kpis'?'bg-cyan-500 text-slate-950 font-bold':'text-slate-400 hover:bg-slate-800 hover:text-white' ?>"><i data-lucide="pie-chart" class="w-4 h-4"></i> Fase 7: Inteligencia & KPIs</a>
                        <a href="?sec=usuarios" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-xs font-semibold tracking-wide <?= $sec==='usuarios'?'bg-cyan-500 text-slate-950 font-bold':'text-slate-400 hover:bg-slate-800 hover:text-white' ?>"><i data-lucide="users" class="w-4 h-4"></i> Fase 2: Gestión Usuarios</a>
                        <a href="?sec=comite" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-xs font-semibold tracking-wide <?= $sec==='comite'?'bg-cyan-500 text-slate-950 font-bold':'text-slate-400 hover:bg-slate-800 hover:text-white' ?>"><i data-lucide="gavel" class="w-4 h-4"></i> Fase 3-4: Comité Evaluación</a>
                        <a href="?sec=caja" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-xs font-semibold tracking-wide <?= $sec==='caja'?'bg-cyan-500 text-slate-950 font-bold':'text-slate-400 hover:bg-slate-800 hover:text-white' ?>"><i data-lucide="banknote" class="w-4 h-4"></i> Fase 5: Mesa de Amortización</a>
                        <a href="?sec=dte_historial" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-xs font-semibold tracking-wide <?= $sec==='dte_historial'?'bg-cyan-500 text-slate-950 font-bold':'text-slate-400 hover:bg-slate-800 hover:text-white' ?>"><i data-lucide="file-check-2" class="w-4 h-4"></i> Fase 6: Auditoría Fiscal DTE</a>
                        <a href="?sec=bitacora" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-xs font-semibold tracking-wide <?= $sec==='bitacora'?'bg-cyan-500 text-slate-950 font-bold':'text-slate-400 hover:bg-slate-800 hover:text-white' ?>"><i data-lucide="activity" class="w-4 h-4"></i> Fase 8: Bitácora de Sucesos</a>
                    <?php else: ?>
                        <div class="text-[10px] font-black tracking-wider text-slate-500 uppercase px-3 mb-2">Portal de Servicios</div>
                        <a href="?sec=asoc_cuenta" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-xs font-semibold tracking-wide <?= $sec==='asoc_cuenta'?'bg-cyan-500 text-slate-950 font-bold':'text-slate-400 hover:bg-slate-800 hover:text-white' ?>"><i data-lucide="wallet" class="w-4 h-4"></i> Resumen de Cuenta</a>
                        <a href="?sec=asoc_simulador" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-xs font-semibold tracking-wide <?= $sec==='asoc_simulador'?'bg-cyan-500 text-slate-950 font-bold':'text-slate-400 hover:bg-slate-800 hover:text-white' ?>"><i data-lucide="calculator" class="w-4 h-4"></i> Simulador & Solicitud</a>
                    <?php endif; ?>
                </nav>
            </div>

            <div class="bg-slate-950 p-4 rounded-2xl border border-slate-800 flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs truncate font-bold text-white"><?= $_SESSION['nombre'] ?></span>
                    <span class="text-[9px] uppercase font-mono bg-cyan-500/20 text-cyan-400 px-1.5 py-0.5 rounded"><?= $rol_sesion ?></span>
                </div>
                <div class="text-[10px] font-mono text-slate-500">ID Único: <?= $_SESSION['carnet'] ?></div>
                <a href="?logout=1" class="text-rose-400 font-bold text-[11px] hover:underline flex items-center gap-1 mt-2"><i data-lucide="log-out" class="w-3.5 h-3.5"></i> Finalizar Conexión</a>
            </div>
        </aside>

        <main class="flex-1 flex flex-col h-full overflow-hidden">
            <header class="h-16 bg-white border-b px-8 flex items-center justify-between shadow-sm flex-shrink-0">
                <h2 class="text-xs font-mono font-bold tracking-widest text-slate-400 uppercase">Módulo Actual / <?= htmlspecialchars($sec) ?></h2>
                <div class="flex items-center gap-4">
                    <?php 
                    $notif_stmt = $db->prepare("SELECT * FROM notificaciones WHERE usuario_id = ? ORDER BY id DESC LIMIT 3");
                    $notif_stmt->execute([$_SESSION['user_id'] ?? 0]);
                    $lista_notif = $notif_stmt->fetchAll();
                    ?>
                    <div class="relative group">
                        <button class="p-2 bg-slate-100 hover:bg-slate-200 rounded-xl relative">
                            <i data-lucide="bell" class="w-4 h-4 text-slate-600"></i>
                            <?php if(count($lista_notif) > 0): ?><span class="absolute top-1 right-1 w-2 h-2 bg-rose-500 rounded-full"></span><?php endif; ?>
                        </button>
                        <div class="absolute right-0 mt-2 w-80 bg-white border rounded-2xl shadow-xl p-4 hidden group-hover:block z-50">
                            <h4 class="text-xs font-bold text-slate-800 border-b pb-2 mb-2">Mensajes Internos Recientes</h4>
                            <div class="space-y-2 text-[11px]">
                                <?php foreach($lista_notif as $n): ?>
                                    <div class="p-2 bg-slate-50 rounded-lg border-l-2 border-cyan-500"><?= htmlspecialchars($n['mensaje']) ?></div>
                                <?php endforeach; if(empty($lista_notif)) echo "<p class='text-slate-400 text-center py-2'>Sin alertas.</p>"; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <?php if ($mensaje): ?>
                <div class="p-4 <?= $tipo_mensaje==='success'?'bg-emerald-600':'bg-rose-600' ?> text-white text-xs font-mono flex items-center gap-2 flex-shrink-0">
                    <i data-lucide="info"></i> <?= htmlspecialchars($mensaje) ?>
                </div>
            <?php endif; ?>

            <div class="flex-1 overflow-y-auto p-8">
                
                <?php if ($sec === 'kpis' && $rol_sesion === 'Analista'): ?>
                    <?php
                    $kpi_col = $db->query("SELECT SUM(monto_aprobado) as total_col, COUNT(*) as prestamos_total FROM prestamos WHERE estado != 'Cancelado'")->fetch();
                    $kpi_mora = $db->query("SELECT SUM(saldo_actual) as total_mora FROM prestamos WHERE estado = 'Mora'")->fetch();
                    $kpi_sol = $db->query("SELECT COUNT(*) as pendientes FROM solicitudes WHERE estado = 'Pending' OR estado = 'Pendiente'")->fetch();
                    ?>
                    <div class="space-y-6 max-w-6xl mx-auto">
                        <div>
                            <h3 class="text-xl font-bold text-slate-900">Fase 7: Inteligencia de Datos Crediticios</h3>
                            <p class="text-xs text-slate-500">Métricas consolidadas de la cooperativa en tiempo real.</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                            <div class="bg-white p-6 rounded-2xl border shadow-sm flex items-center gap-4">
                                <div class="p-3 bg-cyan-100 text-cyan-600 rounded-xl"><i data-lucide="database"></i></div>
                                <div><span class="text-[10px] uppercase font-bold text-slate-400">Cartera Activa Colocada</span><p class="text-xl font-black text-slate-900">$<?= number_format($kpi_col['total_col'] ?? 0, 2) ?></p></div>
                            </div>
                            <div class="bg-white p-6 rounded-2xl border shadow-sm flex items-center gap-4">
                                <div class="p-3 bg-rose-100 text-rose-600 rounded-xl"><i data-lucide="alert-triangle"></i></div>
                                <div><span class="text-[10px] uppercase font-bold text-slate-400">Masa Crítica en Mora</span><p class="text-xl font-black text-rose-600">$<?= number_format($kpi_mora['total_mora'] ?? 0, 2) ?></p></div>
                            </div>
                            <div class="bg-white p-6 rounded-2xl border shadow-sm flex items-center gap-4">
                                <div class="p-3 bg-amber-100 text-amber-600 rounded-xl"><i data-lucide="clock"></i></div>
                                <div><span class="text-[10px] uppercase font-bold text-slate-400">Solicitudes por Dictaminar</span><p class="text-xl font-black text-slate-900"><?= $kpi_sol['pendientes'] ?> En Cola</p></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="bg-white p-6 rounded-2xl border shadow-sm space-y-4">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Distribución Financiera de Riesgo</h4>
                                <div class="h-64 flex justify-center">
                                    <canvas id="chartGlobalCartera"></canvas>
                                </div>
                            </div>
                            <div class="bg-white p-6 rounded-2xl border shadow-sm">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-4">Últimos Desembolsos</h4>
                                <table class="w-full text-xs text-left">
                                    <thead class="bg-slate-50 text-slate-600 font-semibold uppercase">
                                        <tr><th class="p-3">ID Prestamo</th><th class="p-3">Saldo Vigente</th><th class="p-3">Estado</th></tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <?php 
                                        $rows = $db->query("SELECT * FROM prestamos ORDER BY id DESC LIMIT 5")->fetchAll();
                                        foreach($rows as $r):
                                        ?>
                                        <tr>
                                            <td class="p-3 font-mono text-cyan-600">#PR-<?= $r['id'] ?></td>
                                            <td class="p-3 font-bold">$<?= number_format($r['saldo_actual'], 2) ?></td>
                                            <td class="p-3"><span class="px-2 py-0.5 rounded text-[9px] font-bold <?= $r['estado']==='Mora'?'bg-rose-100 text-rose-800':'bg-emerald-100 text-emerald-800' ?>"><?= $r['estado'] ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <script>
                            document.addEventListener("DOMContentLoaded", function() {
                                new Chart(document.getElementById('chartGlobalCartera'), {
                                    type: 'pie',
                                    data: {
                                        labels: ['Sano', 'En Mora'],
                                        datasets: [{
                                            data: [<?= floatval($kpi_col['total_col']) ?>, <?= floatval($kpi_mora['total_mora']) ?>],
                                            backgroundColor: ['#22c55e', '#ef4444']
                                        }]
                                    }
                                });
                            });
                        </script>
                    </div>

                <?php elseif ($sec === 'usuarios' && $rol_sesion === 'Analista'): ?>
                    <?php
                    $buscar = $_GET['q'] ?? '';
                    $query_u = "SELECT * FROM usuarios WHERE activo = 1";
                    if (!empty($buscar)) {
                        $query_u .= " AND (nombre LIKE '%$buscar%' OR carnet LIKE '%$buscar%')";
                    }
                    $lista_u = $db->query($query_u)->fetchAll();
                    
                    $edit_id = $_GET['edit'] ?? '';
                    $u_edit = ['id'=>'','nombre'=>'','carnet'=>'','rol'=>'Asociado','ingresos_mensuales'=>0,'gastos_mensuales'=>0,'score_crediticio'=>700];
                    if (!empty($edit_id)) {
                        $st = $db->prepare("SELECT * FROM usuarios WHERE id = ?"); $st->execute([$edit_id]);
                        $u_edit = $st->fetch() ?: $u_edit;
                    }
                    ?>
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 max-w-6xl mx-auto">
                        <div class="lg:col-span-4 bg-white p-6 rounded-2xl border shadow-sm">
                            <h3 class="text-sm font-bold text-slate-800 mb-4"><?= !empty($u_edit['id']) ? 'Modificar Registro':'Inyectar Nuevo Registro' ?></h3>
                            <form method="POST" class="space-y-4 text-xs">
                                <input type="hidden" name="operacion" value="guardar_usuario">
                                <input type="hidden" name="id" value="<?= $u_edit['id'] ?>">
                                <div><label class="block text-slate-500 font-medium mb-1">Nombre Completo</label><input type="text" name="nombre" required value="<?= htmlspecialchars($u_edit['nombre']) ?>" class="w-full p-2.5 bg-slate-50 border rounded-xl"></div>
                                <div><label class="block text-slate-500 font-medium mb-1">Carnet Identificador</label><input type="text" name="carnet" required value="<?= htmlspecialchars($u_edit['carnet']) ?>" class="w-full p-2.5 bg-slate-50 border rounded-xl font-mono"></div>
                                <div><label class="block text-slate-500 font-medium mb-1">Rol Operativo</label><select name="rol" class="w-full p-2.5 bg-slate-50 border rounded-xl"><option value="Asociado" <?= $u_edit['rol']==='Asociado'?'selected':'' ?>>Asociado (Cliente)</option><option value="Analista" <?= $u_edit['rol']==='Analista'?'selected':'' ?>>Analista (Interno)</option></select></div>
                                <div><label class="block text-slate-500 font-medium mb-1">Ingresos Mensuales ($)</label><input type="number" name="ingresos" step="0.01" value="<?= $u_edit['ingresos_mensuales'] ?>" class="w-full p-2.5 bg-slate-50 border rounded-xl font-mono"></div>
                                <div><label class="block text-slate-500 font-medium mb-1">Gastos Mensuales ($)</label><input type="number" name="gastos" step="0.01" value="<?= $u_edit['gastos_mensuales'] ?>" class="w-full p-2.5 bg-slate-50 border rounded-xl font-mono"></div>
                                <div><label class="block text-slate-500 font-medium mb-1">Score Buró</label><input type="number" name="score" value="<?= $u_edit['score_crediticio'] ?>" class="w-full p-2.5 bg-slate-50 border rounded-xl font-mono"></div>
                                <button class="w-full bg-slate-900 text-white font-bold py-3 rounded-xl">Consolidar en DB</button>
                            </form>
                        </div>

                        <div class="lg:col-span-8 space-y-4">
                            <form method="GET" class="flex gap-2">
                                <input type="hidden" name="sec" value="usuarios">
                                <input type="text" name="q" value="<?= htmlspecialchars($buscar) ?>" placeholder="Filtrar por nombre o carnet..." class="flex-1 p-2.5 bg-white border rounded-xl text-xs">
                                <button class="bg-slate-900 text-white text-xs font-bold px-4 rounded-xl flex items-center gap-1"><i data-lucide="search" class="w-3.5 h-3.5"></i> Buscar</button>
                            </form>

                            <div class="bg-white rounded-2xl border shadow-sm overflow-hidden">
                                <table class="w-full text-xs text-left">
                                    <thead class="bg-slate-50 text-slate-500 font-bold uppercase">
                                        <tr><th class="p-3">Usuario</th><th class="p-3">Rol</th><th class="p-3">Score</th><th class="p-3 text-right">Acciones</th></tr>
                                    </thead>
                                    <tbody class="divide-y text-slate-700">
                                        <?php foreach($lista_u as $u): ?>
                                            <tr>
                                                <td class="p-3">
                                                    <p class="font-bold text-slate-900"><?= htmlspecialchars($u['nombre']) ?></p>
                                                    <p class="font-mono text-[10px] text-slate-400">Carnet: <?= $u['carnet'] ?></p>
                                                </td>
                                                <td class="p-3"><span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $u['rol']==='Analista'?'bg-purple-100 text-purple-800':'bg-blue-100 text-blue-800' ?>"><?= $u['rol'] ?></span></td>
                                                <td class="p-3 font-mono text-slate-600"><?= $u['score_crediticio'] ?></td>
                                                <td class="p-3 text-right flex gap-2 justify-end">
                                                    <a href="?sec=usuarios&edit=<?= $u['id'] ?>" class="p-1.5 bg-slate-100 hover:bg-slate-200 rounded text-slate-600"><i data-lucide="edit" class="w-3.5 h-3.5"></i></a>
                                                    <form method="POST" onsubmit="return confirm('¿Aplicar baja lógica?');" class="inline">
                                                        <input type="hidden" name="operacion" value="eliminar_logico">
                                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                                        <button class="p-1.5 bg-rose-50 text-rose-600 hover:bg-rose-100 rounded"><i data-lucide="trash" class="w-3.5 h-3.5"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                <?php elseif ($sec === 'comite' && $rol_sesion === 'Analista'): ?>
                    <?php
                    $sols = $db->query("SELECT s.*, u.nombre as cliente, u.ingresos_mensuales, u.gastos_mensuales, u.score_crediticio FROM solicitudes s JOIN usuarios u ON s.usuario_id = u.id ORDER BY s.id DESC")->fetchAll();
                    ?>
                    <div class="max-w-4xl mx-auto space-y-6">
                        <div>
                            <h3 class="text-xl font-bold text-slate-900">Mesa Evaluadora & Gestión del Riesgo</h3>
                            <p class="text-xs text-slate-500">Métricas analíticas calculadas por el motor financiero.</p>
                        </div>

                        <?php foreach($sols as $s): ?>
                            <div class="bg-white rounded-2xl border shadow-sm p-6 space-y-4">
                                <div class="flex justify-between items-start border-b pb-3">
                                    <div>
                                        <h4 class="font-bold text-sm text-slate-900"><?= htmlspecialchars($s['cliente']) ?></h4>
                                        <p class="text-xs text-slate-400">Solicitud ID: <span class="font-mono text-cyan-600">#SOL-<?= $s['id'] ?></span></p>
                                    </div>
                                    <span class="px-2.5 py-1 text-xs font-bold rounded-lg <?= $s['estado']==='Pendiente'?'bg-amber-100 text-amber-800':($s['estado']==='Aprobado'?'bg-emerald-100 text-emerald-800':'bg-rose-100 text-rose-800') ?>"><?= $s['estado'] ?></span>
                                </div>

                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs font-mono">
                                    <div class="bg-slate-50 p-3 rounded-xl"><span class="text-[9px] text-slate-400 font-bold uppercase block">Monto / Plazo</span><strong>$<?= number_format($s['monto_solicitado'],2) ?></strong> al <?= $s['plazo_meses'] ?>m</div>
                                    <div class="bg-slate-50 p-3 rounded-xl"><span class="text-[9px] text-slate-400 font-bold uppercase block">Buró Crediticio</span><strong><?= $s['score_crediticio'] ?> Pts</strong></div>
                                    <div class="bg-slate-50 p-3 rounded-xl"><span class="text-[9px] text-slate-400 font-bold uppercase block">Excedente Libre</span><strong>$<?= number_format($s['capacidad_pago'],2) ?>/m</strong></div>
                                    <div class="bg-slate-50 p-3 rounded-xl"><span class="text-[9px] text-slate-400 font-bold uppercase block">Relación Cuota/Ingreso</span><strong><?= round($s['relacion_cuota_ingreso'], 1) ?> %</strong></div>
                                </div>

                                <div class="text-xs bg-slate-900 text-slate-400 p-2.5 rounded-xl flex items-center justify-between font-mono">
                                    <span>📄 Documentación de Respaldo Salarial:</span>
                                    <span class="text-[10px] text-cyan-400 underline truncate max-w-xs"><?= substr($s['documento_adjunto'], 0, 35) ?>...</span>
                                </div>

                                <?php if($s['estado'] === 'Pendiente'): ?>
                                    <form method="POST" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end pt-2">
                                        <input type="hidden" name="operacion" value="evaluar_solicitud">
                                        <input type="hidden" name="solicitud_id" value="<?= $s['id'] ?>">
                                        <div class="sm:col-span-8">
                                            <input type="text" name="observaciones" required placeholder="Añadir observaciones de aprobación o rechazo..." class="w-full p-2.5 bg-slate-50 border rounded-xl text-xs">
                                        </div>
                                        <div class="sm:col-span-4 flex gap-2">
                                            <button name="estado_evaluacion" value="Aprobado" class="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white font-bold p-2.5 text-xs rounded-xl">Aprobar</button>
                                            <button name="estado_evaluacion" value="Rechazado" class="flex-1 bg-rose-600 hover:bg-rose-500 text-white font-bold p-2.5 text-xs rounded-xl">Rechazar</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <p class="text-[11px] text-slate-600 bg-slate-100 p-2.5 rounded-xl font-medium">📌 <strong>Resolución:</strong> <?= htmlspecialchars($s['observaciones']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($sec === 'caja' && $rol_sesion === 'Analista'): ?>
                    <?php
                    $prestamos_activos = $db->query("SELECT p.*, u.nombre as cliente FROM prestamos p JOIN solicitudes s ON p.solicitud_id = s.id JOIN usuarios u ON s.usuario_id = u.id WHERE p.estado != 'Cancelado'")->fetchAll();
                    ?>
                    <div class="max-w-md mx-auto bg-white p-8 rounded-3xl border shadow-sm space-y-6">
                        <div>
                            <h3 class="text-base font-bold text-slate-900">Ventanilla de Liquidación y Cobros</h3>
                            <p class="text-xs text-slate-400">Abonos bajo el esquema decreciente francés.</p>
                        </div>

                        <form method="POST" class="space-y-4 text-xs">
                            <input type="hidden" name="operacion" value="procesar_pago">
                            <div>
                                <label class="block text-slate-500 font-medium mb-1">Seleccionar Cuenta de Destino</label>
                                <select name="prestamo_id" class="w-full p-3 bg-slate-50 border rounded-xl font-mono font-bold text-slate-800">
                                    <?php foreach($prestamos_activos as $pa): ?>
                                        <option value="<?= $pa['id'] ?>">PR-<?= $pa['id'] ?> - <?= htmlspecialchars($pa['cliente']) ?> (Saldo: $<?= number_format($pa['saldo_actual'], 2) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-slate-500 font-medium mb-1">Monto Líquido del Abono ($)</label>
                                <input type="number" name="monto_pago" step="0.01" required placeholder="0.00" class="w-full p-3 bg-slate-50 border rounded-xl font-mono font-bold text-lg text-slate-900 focus:outline-none focus:border-cyan-500">
                            </div>
                            <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-3 rounded-xl">
                                Aplicar Abono a Capital e Intereses
                            </button>
                        </form>
                    </div>

                <?php elseif ($sec === 'dte_historial' && $rol_sesion === 'Analista'): ?>
                    <?php
                    $dtes = $db->query("SELECT d.*, u.nombre as cliente FROM facturas_dte d JOIN pagos p ON d.pago_id = p.id JOIN prestamos pr ON p.prestamo_id = pr.id JOIN solicitudes s ON pr.solicitud_id = s.id JOIN usuarios u ON s.usuario_id = u.id ORDER BY d.id DESC")->fetchAll();
                    ?>
                    <div class="max-w-4xl mx-auto space-y-6">
                        <div>
                            <h3 class="text-xl font-bold text-slate-900">Libro de Documentos Tributarios Electrónicos (DTE)</h3>
                            <p class="text-xs text-slate-500">Comprobantes firmados electrónicamente e invalidados bajo norma oficial.</p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <?php foreach($dtes as $d): ?>
                                <div class="bg-slate-950 text-slate-200 p-5 rounded-2xl font-mono text-xs border border-slate-800 flex flex-col justify-between space-y-3 relative overflow-hidden shadow-xl">
                                    <?php if($d['estado_dte'] === 'Anulado'): ?>
                                        <div class="absolute inset-0 bg-rose-950/80 backdrop-blur-xs flex items-center justify-center z-10">
                                            <span class="text-rose-400 font-black text-lg border-2 border-rose-400 px-4 py-1 rounded-xl tracking-widest uppercase">ANULADO</span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="flex justify-between items-start">
                                        <div>
                                            <span class="text-[10px] text-cyan-400 font-bold block"><?= $d['tipo_documento'] ?></span>
                                            <span class="text-[10px] text-slate-500">Cliente: <?= htmlspecialchars($d['cliente']) ?></span>
                                        </div>
                                        <div class="text-right"><span class="text-white font-bold text-sm block">$<?= number_format($d['monto_afecto'], 2) ?></span></div>
                                    </div>

                                    <div class="bg-slate-900 p-2 rounded-xl text-[10px] text-slate-400 space-y-1">
                                        <p class="truncate"><strong>SELLO:</strong> <?= $d['sello_recepcion'] ?></p>
                                        <p><strong>IVA (13% s/Int):</strong> $<?= number_format($d['iva'], 2) ?></p>
                                    </div>

                                    <div class="flex gap-2 justify-end relative z-20">
                                        <a href="?descargar_dte=<?= $d['id'] ?>" class="bg-slate-800 hover:bg-slate-700 text-white px-3 py-1.5 rounded-lg text-[10px] flex items-center gap-1"><i data-lucide="download" class="w-3 h-3"></i> Descargar Factura</a>
                                        <?php if($d['estado_dte'] !== 'Anulado'): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="operacion" value="anular_dte">
                                                <input type="hidden" name="dte_id" value="<?= $d['id'] ?>">
                                                <button class="bg-rose-950 text-rose-400 border border-rose-800 px-3 py-1.5 rounded-lg text-[10px]">Anular DTE</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php elseif ($sec === 'bitacora' && $rol_sesion === 'Analista'): ?>
                    <?php
                    $logs = $db->query("SELECT * FROM bitacora ORDER BY id DESC LIMIT 50")->fetchAll();
                    ?>
                    <div class="max-w-4xl mx-auto space-y-4">
                        <div>
                            <h3 class="text-xl font-bold text-slate-900">Trazabilidad Interna de Auditoría</h3>
                            <p class="text-xs text-slate-500">Historial inmutable de operaciones.</p>
                        </div>
                        <div class="bg-white rounded-2xl border shadow-sm overflow-hidden">
                            <table class="w-full text-xs text-left font-mono">
                                <thead class="bg-slate-900 text-white">
                                    <tr><th class="p-3">Tiempo</th><th class="p-3">Operador</th><th class="p-3">Evento</th><th class="p-3">Detalles</th></tr>
                                </thead>
                                <tbody class="divide-y text-slate-700">
                                    <?php foreach($logs as $l): ?>
                                        <tr>
                                            <td class="p-3 text-[10px] text-slate-400"><?= $l['fecha'] ?></td>
                                            <td class="p-3 font-bold"><?= htmlspecialchars($l['usuario']) ?></td>
                                            <td class="p-3"><span class="px-2 py-0.5 bg-slate-100 rounded text-cyan-700 font-bold"><?= $l['accion'] ?></span></td>
                                            <td class="p-3 text-slate-500"><?= htmlspecialchars($l['detalles']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($sec === 'asoc_cuenta' && $rol_sesion === 'Asociado'): ?>
                    <?php
                    $my_id = $_SESSION['user_id'];
                    $mis_p = $db->query("SELECT p.*, s.plazo_meses FROM prestamos p JOIN solicitudes s ON p.solicitud_id = s.id WHERE s.usuario_id = $my_id")->fetchAll();
                    ?>
                    <div class="max-w-4xl mx-auto space-y-6">
                        <h3 class="text-lg font-bold text-slate-900">Mis Líneas de Crédito Vigentes</h3>
                        
                        <?php foreach($mis_p as $mp): 
                            $st_pagos = $db->prepare("SELECT SUM(monto_pago) as total FROM pagos WHERE prestamo_id = ?");
                            $st_pagos->execute([$mp['id']]);
                            $pagado = floatval($st_pagos->fetch()['total'] ?? 0);
                            $saldo = $mp['saldo_actual'];
                        ?>
                            <div class="bg-white p-6 rounded-2xl border shadow-sm space-y-4">
                                <div class="flex justify-between items-center border-b pb-3">
                                    <span class="text-xs font-bold text-cyan-600 font-mono">CRÉDITO #PR-<?= $mp['id'] ?></span>
                                    <span class="px-2.5 py-0.5 rounded text-xs font-bold bg-emerald-100 text-emerald-700"><?= $mp['estado'] ?></span>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
                                    <div class="md:col-span-8 grid grid-cols-3 gap-4 text-center text-xs font-mono">
                                        <div class="bg-slate-50 p-3 rounded-xl"><span class="text-slate-400 block text-[9px]">Aprobado</span><strong>$<?= number_format($mp['monto_aprobado'],2) ?></strong></div>
                                        <div class="bg-slate-50 p-3 rounded-xl"><span class="text-slate-400 block text-[9px]">Abonado</span><strong class="text-emerald-600">$<?= number_format($pagado,2) ?></strong></div>
                                        <div class="bg-slate-50 p-3 rounded-xl"><span class="text-slate-400 block text-[9px]">Saldo</span><strong class="text-rose-600">$<?= number_format($saldo,2) ?></strong></div>
                                    </div>
                                    <div class="md:col-span-4 flex justify-center h-24">
                                        <canvas id="chartAsoc_<?= $mp['id'] ?>"></canvas>
                                    </div>
                                </div>
                                <script>
                                    document.addEventListener("DOMContentLoaded", function() {
                                        new Chart(document.getElementById('chartAsoc_<?= $mp['id'] ?>'), {
                                            type: 'doughnut',
                                            data: {
                                                labels: ['Abonado', 'Saldo'],
                                                datasets: [{ data: [<?= $pagado ?>, <?= $saldo ?>], backgroundColor: ['#10b981', '#f43f5e'] }]
                                            },
                                            options: { plugins: { legend: { display: false } }, cutout: '75%' }
                                        });
                                    });
                                </script>
                            </div>
                        <?php endforeach; if(empty($mis_p)) echo "<p class='text-xs text-slate-400 text-center bg-white p-6 rounded-2xl border'>No posees créditos activos en este momento.</p>"; ?>
                    </div>

                <?php elseif ($sec === 'asoc_simulador' && $rol_sesion === 'Asociado'): ?>
                    <?php
                    $s_monto = isset($_POST['s_monto']) ? floatval($_POST['s_monto']) : 2000;
                    $s_plazo = isset($_POST['s_plazo']) ? intval($_POST['s_plazo']) : 12;
                    $tabla_sim = calcularAmortizacionFrancesa($s_monto, $s_plazo, 0.12);
                    $cuota_fija = !empty($tabla_sim) ? $tabla_sim[0]['cuota'] : 0;
                    ?>
                    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 max-w-6xl mx-auto">
                        <div class="lg:col-span-5 bg-white p-6 rounded-2xl border shadow-sm space-y-4">
                            <h3 class="text-sm font-bold text-slate-800">Estructurar Solicitud Oficial</h3>
                            <form method="POST" enctype="multipart/form-data" class="space-y-4 text-xs">
                                <input type="hidden" name="operacion" value="crear_solicitud">
                                <div><label class="block text-slate-500 font-medium mb-1">Monto Líquido a Solicitar ($)</label><input type="number" name="monto" value="<?= $s_monto ?>" class="w-full p-2.5 bg-slate-50 border rounded-xl font-mono font-bold text-slate-800"></div>
                                <div><label class="block text-slate-500 font-medium mb-1">Plazo</label><select name="plazo" class="w-full p-2.5 bg-slate-50 border rounded-xl font-bold"><option value="12" <?= $s_plazo==12?'selected':'' ?>>12 Meses</option><option value="24" <?= $s_plazo==24?'selected':'' ?>>24 Meses</option><option value="36" <?= $s_plazo==36?'selected':'' ?>>36 Meses</option></select></div>
                                <div><label class="block text-slate-500 font-medium mb-1">Comprobantes de Ingresos (PDF/Imagen)</label><input type="file" name="documento" class="w-full p-2 bg-slate-50 border border-dashed rounded-xl"></div>
                                <div class="bg-cyan-950 p-4 rounded-xl text-cyan-300 font-mono text-[11px]">
                                    <p>Tasa Base: 12.00 % Anual | Cuota Fija: $<?= number_format($cuota_fija, 2) ?>/mes</p>
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" formaction="?sec=asoc_simulador" class="flex-1 bg-slate-200 text-slate-700 font-bold py-3 rounded-xl">Simular</button>
                                    <button type="submit" class="flex-1 bg-slate-900 text-white font-bold py-3 rounded-xl shadow-md">Enviar</button>
                                </div>
                            </form>
                        </div>

                        <div class="lg:col-span-7 bg-white p-6 rounded-2xl border shadow-sm space-y-4">
                            <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider">Amortización Progresiva (Sistema Francés)</h3>
                            <div class="max-h-80 overflow-y-auto border rounded-xl">
                                <table class="w-full text-left text-[11px] font-mono">
                                    <tbody class="divide-y text-slate-700">
                                        <?php foreach($tabla_sim as $ts): ?>
                                            <tr>
                                                <td class="p-2 text-center bg-slate-50">Mes <?= $ts['mes'] ?></td>
                                                <td class="p-2 font-bold">$<?= number_format($ts['cuota'],2) ?></td>
                                                <td class="p-2 text-rose-600">-$<?= number_format($ts['interes'],2) ?></td>
                                                <td class="p-2 text-emerald-600">+$<?= number_format($ts['capital'],2) ?></td>
                                                <td class="p-2 text-slate-400">$<?= number_format($ts['saldo'],2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </main>
    </div>
<?php endif; ?>

<script>lucide.createIcons();</script>
</body>
</html>
