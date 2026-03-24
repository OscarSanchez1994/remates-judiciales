<?php
// ── DB connection ──────────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
        getenv('DB_USER'), getenv('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

// ── AJAX: toggle procesado ────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'toggle_procesado') {
    header('Content-Type: application/json');
    $aid = preg_replace('/\D/', '', $_POST['article_id'] ?? '');
    if (!$aid) { echo json_encode(['error' => 'invalid']); exit; }
    $pdo    = db();
    $exists = $pdo->prepare('SELECT 1 FROM procesados WHERE article_id = ?');
    $exists->execute([$aid]);
    if ($exists->fetchColumn()) {
        $pdo->prepare('DELETE FROM procesados WHERE article_id = ?')->execute([$aid]);
        echo json_encode(['procesado' => false]);
    } else {
        $pdo->prepare('INSERT INTO procesados (article_id) VALUES (?) ON DUPLICATE KEY UPDATE procesado_at = NOW()')->execute([$aid]);
        echo json_encode(['procesado' => true]);
    }
    exit;
}

// ── AJAX: get remates vehiculos for an article ────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_remates') {
    header('Content-Type: application/json');
    $aid = preg_replace('/\D/', '', $_GET['aid'] ?? '');
    if (!$aid) { echo json_encode([]); exit; }
    $stmt = db()->prepare('SELECT * FROM remates_vehiculos WHERE article_id = ? ORDER BY id ASC');
    $stmt->execute([$aid]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── AJAX: save remate vehiculo ────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'save_remate') {
    header('Content-Type: application/json');
    $aid     = preg_replace('/\D/', '', $_POST['article_id'] ?? '');
    $radicado = trim($_POST['radicado'] ?? '');
    if (!$aid || !$radicado) { echo json_encode(['error' => 'Radicado es obligatorio']); exit; }
    $stmt = db()->prepare('
        INSERT INTO remates_vehiculos
            (article_id, titulo_pub, radicado, marca, modelo, anio, placa, color,
             avaluo, base_remate, fecha_remate, hora_remate, modalidad, notas)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $stmt->execute([
        $aid,
        trim($_POST['titulo_pub']   ?? ''),
        $radicado,
        trim($_POST['marca']        ?? ''),
        trim($_POST['modelo']       ?? ''),
        trim($_POST['anio']         ?? ''),
        strtoupper(trim($_POST['placa'] ?? '')),
        trim($_POST['color']        ?? ''),
        trim($_POST['avaluo']       ?? ''),
        trim($_POST['base_remate']  ?? ''),
        trim($_POST['fecha_remate'] ?? ''),
        trim($_POST['hora_remate']  ?? ''),
        trim($_POST['modalidad']    ?? ''),
        trim($_POST['notas']        ?? ''),
    ]);
    $newId = db()->lastInsertId();
    $row   = db()->prepare('SELECT * FROM remates_vehiculos WHERE id = ?');
    $row->execute([$newId]);
    echo json_encode(['ok' => true, 'remate' => $row->fetch(PDO::FETCH_ASSOC)]);
    exit;
}

// ── AJAX: update remate vehiculo ─────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'update_remate') {
    header('Content-Type: application/json');
    $id       = (int) ($_POST['id'] ?? 0);
    $aid      = preg_replace('/\D/', '', $_POST['article_id'] ?? '');
    $radicado = trim($_POST['radicado'] ?? '');
    if (!$id || !$aid || !$radicado) { echo json_encode(['error' => 'Radicado es obligatorio']); exit; }
    db()->prepare('
        UPDATE remates_vehiculos SET
            radicado=?, marca=?, modelo=?, anio=?, placa=?, color=?,
            avaluo=?, base_remate=?, fecha_remate=?, hora_remate=?, modalidad=?, notas=?
        WHERE id=? AND article_id=?
    ')->execute([
        $radicado,
        trim($_POST['marca']        ?? ''),
        trim($_POST['modelo']       ?? ''),
        trim($_POST['anio']         ?? ''),
        strtoupper(trim($_POST['placa'] ?? '')),
        trim($_POST['color']        ?? ''),
        trim($_POST['avaluo']       ?? ''),
        trim($_POST['base_remate']  ?? ''),
        trim($_POST['fecha_remate'] ?? ''),
        trim($_POST['hora_remate']  ?? ''),
        trim($_POST['modalidad']    ?? ''),
        trim($_POST['notas']        ?? ''),
        $id, $aid,
    ]);
    $row = db()->prepare('SELECT * FROM remates_vehiculos WHERE id=?');
    $row->execute([$id]);
    echo json_encode(['ok' => true, 'remate' => $row->fetch(PDO::FETCH_ASSOC)]);
    exit;
}

// ── AJAX: delete remate vehiculo ──────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete_remate') {
    header('Content-Type: application/json');
    $id  = (int) ($_POST['id'] ?? 0);
    $aid = preg_replace('/\D/', '', $_POST['article_id'] ?? '');
    if (!$id || !$aid) { echo json_encode(['error' => 'invalid']); exit; }
    db()->prepare('DELETE FROM remates_vehiculos WHERE id = ? AND article_id = ?')->execute([$id, $aid]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Config ─────────────────────────────────────────────────────────────────────
const PER_PAGE = 20;
const CUTOFF   = '2025-10-01';

// ── Query params ───────────────────────────────────────────────────────────────
$page      = max(1, (int) ($_GET['pagina'] ?? 1));
$orden     = ($_GET['orden'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$pendientes = isset($_GET['pendientes']);
$buscar    = trim($_GET['buscar'] ?? '');
$error     = null;
$items     = [];
$total     = 0;
$totalPages = 0;
$elapsed    = 0;
$dbEmpty    = false;

function pageUrl(int $p, string $orden, bool $pendientes = false, string $buscar = ''): string {
    $q = ['pagina' => $p, 'orden' => $orden];
    if ($pendientes) $q['pendientes'] = '1';
    if ($buscar !== '') $q['buscar'] = $buscar;
    return '?' . http_build_query($q);
}

// ── Migration: ensure fecha_pub_date column exists ────────────────────────────
$colCheck = db()->query("SHOW COLUMNS FROM publicaciones LIKE 'fecha_pub_date'")->fetchAll();
if (empty($colCheck)) {
    db()->exec("ALTER TABLE publicaciones ADD COLUMN fecha_pub_date DATE NULL");
    try { db()->exec("ALTER TABLE publicaciones ADD INDEX idx_fecha_pub (fecha_pub_date)"); } catch (Throwable) {}
}

// ── Fetch from DB ──────────────────────────────────────────────────────────────
try {
    $t0        = microtime(true);
    $pdo       = db();
    $dir       = $orden === 'asc' ? 'ASC' : 'DESC';

    $joinClause  = $pendientes
        ? 'LEFT JOIN procesados pr ON pr.article_id = p.article_id'
        : '';
    $whereClause = $pendientes
        ? 'WHERE p.fecha_pub_date >= ? AND pr.article_id IS NULL'
        : 'WHERE p.fecha_pub_date >= ?';
    $queryParams = [CUTOFF];

    if ($buscar !== '') {
        $whereClause .= ' AND LOWER(p.titulo) LIKE ?';
        $queryParams[] = '%' . mb_strtolower($buscar, 'UTF-8') . '%';
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM publicaciones p $joinClause $whereClause");
    $countStmt->execute($queryParams);
    $total = (int) $countStmt->fetchColumn();

    if ($total === 0 && !$pendientes && $buscar === '') {
        $anyStmt = $pdo->query('SELECT COUNT(*) FROM publicaciones');
        $dbEmpty = ((int) $anyStmt->fetchColumn()) === 0;
    }

    $totalPages = $total > 0 ? (int) ceil($total / PER_PAGE) : 0;
    $page       = max(1, min($page, max(1, $totalPages)));
    $offset     = ($page - 1) * PER_PAGE;

    $stmt = $pdo->prepare("
        SELECT p.article_id, p.titulo, p.despacho, p.fecha_pub_portal AS fecha, p.enlace
        FROM publicaciones p
        $joinClause $whereClause
        ORDER BY p.fecha_pub_date $dir, p.id $dir
        LIMIT " . PER_PAGE . " OFFSET $offset
    ");
    $stmt->execute($queryParams);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $elapsed = round(microtime(true) - $t0, 3);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$from = $total > 0 ? (($page - 1) * PER_PAGE + 1) : 0;
$to   = min($page * PER_PAGE, $total);

// ── Load per-article DB state (procesados + remates count) ────────────────────
$procesadosSet = [];
$rematesCount  = [];
try {
    $aids = array_column($items, 'article_id');
    if ($aids) {
        $in    = implode(',', array_fill(0, count($aids), '?'));
        $stmt  = db()->prepare("SELECT article_id FROM procesados WHERE article_id IN ($in)");
        $stmt->execute($aids);
        $procesadosSet = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

        $stmt2 = db()->prepare("SELECT article_id, COUNT(*) AS cnt FROM remates_vehiculos WHERE article_id IN ($in) GROUP BY article_id");
        $stmt2->execute($aids);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rematesCount[$row['article_id']] = (int) $row['cnt'];
        }
    }
} catch (Throwable) { /* silently ignore */ }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remates Judiciales - Bogotá</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background: #f0f2f5; color: #1a1a2e; min-height: 100vh; }

        /* ── Header ───────────────────────────────────── */
        header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #fff; padding: 24px 32px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap; box-shadow: 0 2px 12px rgba(0,0,0,0.3);
        }
        header .brand { display: flex; align-items: center; gap: 12px; }
        header .brand-icon { font-size: 2rem; }
        header h1 { font-size: 1.4rem; font-weight: 700; line-height: 1.2; }
        header p.subtitle { font-size: 0.85rem; color: #a0c4ff; margin-top: 2px; }
        header .badge { background: #e94560; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; white-space: nowrap; }

        /* ── Main layout ──────────────────────────────── */
        main { max-width: 1300px; margin: 0 auto; padding: 24px 16px; }

        /* ── Stats bar ────────────────────────────────── */
        .stats-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
        .stats-info { font-size: 0.9rem; color: #555; }
        .stats-info strong { color: #0f3460; font-size: 1rem; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 18px; border-radius: 6px; font-size: 0.875rem; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.15s; border: none; }
        .btn-primary { background: #0f3460; color: #fff; }
        .btn-primary:hover { background: #16213e; }
        .btn-success { background: #2d8a4e; color: #fff; }
        .btn-success:hover { background: #246b3d; }

        /* ── Alerts ───────────────────────────────────── */
        .alert { padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
        .alert-error { background: #fdecea; border-left: 4px solid #e94560; color: #7d1a2a; }
        .alert-empty { background: #e8f4ff; border-left: 4px solid #0f3460; color: #1a3a5c; text-align: center; padding: 32px; }
        .alert-info  { background: #fff8e1; border-left: 4px solid #f59e0b; color: #78350f; }

        /* ── Table ────────────────────────────────────── */
        .table-wrapper { background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 8px rgba(0,0,0,0.08); }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        thead tr { background: #0f3460; color: #fff; }
        thead th { padding: 12px 14px; text-align: left; font-weight: 600; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
        thead th:first-child { width: 42px; text-align: center; }
        tbody tr { border-bottom: 1px solid #eef0f5; transition: background 0.1s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: #f5f8ff; }
        td { padding: 12px 14px; vertical-align: middle; }
        td:first-child { text-align: center; color: #999; font-size: 0.8rem; }
        .titulo-cell { font-weight: 600; color: #1a1a2e; line-height: 1.4; vertical-align: top; padding-top: 14px; }
        .despacho-cell { color: #555; font-size: 0.83rem; line-height: 1.4; vertical-align: top; padding-top: 14px; }
        .fecha-cell { color: #666; font-size: 0.83rem; white-space: nowrap; }
        .link-cell { text-align: center; }
        .btn-detail { display: inline-flex; align-items: center; gap: 4px; padding: 5px 12px; background: #e94560; color: #fff; border-radius: 5px; text-decoration: none; font-size: 0.78rem; font-weight: 600; white-space: nowrap; transition: background 0.15s; }
        .btn-detail:hover { background: #c73652; }

        /* ── Procesado column ─────────────────────────── */
        .procesado-cell { text-align: center; }
        .procesado-label { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; border: 2px solid #d1d5db; cursor: pointer; transition: all 0.15s; background: #fff; font-size: 1rem; user-select: none; }
        .procesado-label:hover { border-color: #2d8a4e; background: #f0fdf4; }
        .procesado-label.checked { background: #2d8a4e; border-color: #2d8a4e; color: #fff; }
        tr.is-procesado { opacity: 0.45; }
        tr.is-procesado .titulo-cell { text-decoration: line-through; color: #888; }

        /* ── Agregar Remate column ────────────────────── */
        .remate-cell { text-align: center; }
        .btn-add-remate {
            display: inline-flex; align-items: center; justify-content: center; gap: 5px;
            padding: 5px 10px; border-radius: 6px; border: 2px solid #7c3aed;
            background: #fff; color: #7c3aed; cursor: pointer;
            font-size: 0.78rem; font-weight: 700; white-space: nowrap;
            transition: all 0.15s; user-select: none;
        }
        .btn-add-remate:hover { background: #7c3aed; color: #fff; }
        .btn-add-remate .cnt-badge {
            display: inline-flex; align-items: center; justify-content: center;
            background: #7c3aed; color: #fff; border-radius: 10px;
            padding: 1px 6px; font-size: 0.72rem; font-weight: 700;
            min-width: 18px;
        }
        .btn-add-remate:hover .cnt-badge { background: #fff; color: #7c3aed; }

        /* ── Sync button ──────────────────────────────── */
        #btn-sync {
            color: #fff; text-decoration: none; font-size: .82rem; padding: 5px 14px;
            border: 1px solid rgba(255,255,255,.4); border-radius: 5px;
            background: rgba(255,255,255,.1); font-weight: 600; cursor: pointer;
            transition: all 0.15s;
        }
        #btn-sync:hover:not(:disabled) { background: rgba(255,255,255,.2); }
        #btn-sync:disabled { opacity: 0.65; cursor: wait; }
        #sync-result { font-size: .78rem; color: #a0c4ff; margin-top: 4px; text-align: right; min-height: 1em; }

        /* ── Pagination ───────────────────────────────── */
        .pagination { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 24px; flex-wrap: wrap; }
        .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 10px; border-radius: 6px; font-size: 0.875rem; font-weight: 500; text-decoration: none; transition: all 0.15s; }
        .pagination a { background: #fff; color: #0f3460; border: 1px solid #dce3f0; }
        .pagination a:hover { background: #0f3460; color: #fff; border-color: #0f3460; }
        .pagination .current { background: #0f3460; color: #fff; border: 1px solid #0f3460; }
        .pagination .disabled { color: #bbb; border: 1px solid #eee; cursor: default; pointer-events: none; }
        .pagination .ellipsis { color: #999; border: none; background: transparent; }

        /* ── Footer ───────────────────────────────────── */
        footer { text-align: center; padding: 24px 16px; font-size: 0.78rem; color: #999; }
        footer a { color: #0f3460; text-decoration: none; }

        @media (max-width: 768px) {
            thead th:nth-child(3), td:nth-child(3) { display: none; }
            header h1 { font-size: 1.1rem; }
        }

        /* ── Modal ────────────────────────────────────── */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.55); z-index: 1000;
            align-items: center; justify-content: center; padding: 16px;
        }
        .modal-overlay.open { display: flex; }
        .modal {
            background: #fff; border-radius: 12px;
            width: 100%; max-width: 720px; max-height: 90vh;
            display: flex; flex-direction: column;
            box-shadow: 0 8px 40px rgba(0,0,0,0.25);
            overflow: hidden;
        }
        .modal-header {
            background: linear-gradient(135deg, #1a1a2e, #0f3460);
            color: #fff; padding: 18px 24px;
            display: flex; align-items: flex-start; gap: 12px;
        }
        .modal-header .modal-title { flex: 1; }
        .modal-header h2 { font-size: 1rem; font-weight: 700; }
        .modal-header p { font-size: 0.78rem; color: #a0c4ff; margin-top: 3px; line-height: 1.3; }
        .modal-close { background: none; border: none; color: #fff; font-size: 1.4rem; cursor: pointer; opacity: 0.7; line-height: 1; padding: 0 4px; flex-shrink: 0; }
        .modal-close:hover { opacity: 1; }
        .modal-body { flex: 1; overflow-y: auto; padding: 24px; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid #eef0f5; display: flex; gap: 10px; justify-content: flex-end; }

        /* Existing remates list */
        .remates-list { margin-bottom: 24px; }
        .remates-list h3 { font-size: 0.85rem; font-weight: 700; color: #0f3460; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
        .remate-card {
            background: #f5f8ff; border: 1px solid #dce3f0; border-radius: 8px;
            padding: 14px 16px; margin-bottom: 10px;
            display: grid; grid-template-columns: 1fr auto; gap: 8px; align-items: start;
        }
        .remate-card .rc-radicado { font-weight: 700; color: #0f3460; font-size: 0.9rem; }
        .remate-card .rc-details { font-size: 0.8rem; color: #555; margin-top: 4px; display: flex; flex-wrap: wrap; gap: 6px 14px; }
        .remate-card .rc-details span { white-space: nowrap; }
        .remate-card .rc-notas { font-size: 0.78rem; color: #777; margin-top: 6px; font-style: italic; }
        .btn-delete-remate { background: none; border: 1px solid #e94560; color: #e94560; border-radius: 5px; padding: 4px 10px; font-size: 0.75rem; cursor: pointer; white-space: nowrap; transition: all 0.15s; }
        .btn-delete-remate:hover { background: #e94560; color: #fff; }
        .no-remates-msg { font-size: 0.85rem; color: #999; font-style: italic; margin-bottom: 16px; }

        /* Editable card */
        .remate-card.view-mode { cursor: pointer; }
        .remate-card.view-mode:hover { border-color: #7c3aed; background: #faf5ff; }
        .remate-card .rc-edit-hint { font-size: 0.72rem; color: #7c3aed; margin-top: 6px; opacity: 0; transition: opacity 0.15s; }
        .remate-card.view-mode:hover .rc-edit-hint { opacity: 1; }
        .remate-card.edit-mode { border-color: #7c3aed; background: #faf5ff; grid-template-columns: 1fr; }
        .edit-form-grid { display: grid; gap: 10px; margin-top: 12px; }
        .edit-form-grid.cols-1 { grid-template-columns: 1fr; }
        .edit-form-grid.cols-2 { grid-template-columns: 1fr 1fr; }
        .edit-form-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
        .edit-form-grid .form-group label { font-size: 0.72rem; }
        .edit-form-grid .form-group input,
        .edit-form-grid .form-group select,
        .edit-form-grid .form-group textarea { font-size: 0.82rem; padding: 6px 8px; }
        .edit-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; padding-top: 10px; border-top: 1px solid #e9d8fd; }
        .btn-save-edit { background: #7c3aed; color: #fff; border: none; border-radius: 5px; padding: 6px 16px; font-size: 0.8rem; font-weight: 700; cursor: pointer; transition: background 0.15s; }
        .btn-save-edit:hover { background: #6d28d9; }
        .btn-cancel-edit { background: none; border: 1px solid #d1d5db; color: #555; border-radius: 5px; padding: 6px 14px; font-size: 0.8rem; cursor: pointer; transition: all 0.15s; }
        .btn-cancel-edit:hover { background: #f3f4f6; }
        @media (max-width: 560px) {
            .edit-form-grid.cols-2, .edit-form-grid.cols-3 { grid-template-columns: 1fr; }
        }

        /* Form */
        .form-divider { border: none; border-top: 2px solid #eef0f5; margin: 20px 0 18px; }
        .form-section-title { font-size: 0.85rem; font-weight: 700; color: #7c3aed; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; }
        .form-grid { display: grid; gap: 14px; }
        .form-grid.cols-1 { grid-template-columns: 1fr; }
        .form-grid.cols-2 { grid-template-columns: 1fr 1fr; }
        .form-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 0.78rem; font-weight: 600; color: #444; text-transform: uppercase; letter-spacing: 0.3px; }
        .form-group label .req { color: #e94560; margin-left: 2px; }
        .form-group input, .form-group select, .form-group textarea {
            border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 10px;
            font-size: 0.875rem; font-family: inherit; background: #fff;
            transition: border-color 0.15s;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,0.1);
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group input.invalid { border-color: #e94560; }
        .btn-save { background: #7c3aed; color: #fff; padding: 10px 24px; border-radius: 6px; border: none; font-size: 0.875rem; font-weight: 700; cursor: pointer; transition: background 0.15s; }
        .btn-save:hover { background: #6d28d9; }
        .btn-cancel { background: #f3f4f6; color: #555; padding: 10px 20px; border-radius: 6px; border: none; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: background 0.15s; }
        .btn-cancel:hover { background: #e5e7eb; }

        @media (max-width: 560px) {
            .form-grid.cols-2, .form-grid.cols-3 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header>
    <div class="brand">
        <span class="brand-icon">⚖️</span>
        <div>
            <h1>Remates Judiciales</h1>
            <p class="subtitle">Publicaciones Procesales · Bogotá D.C.</p>
        </div>
    </div>
    <nav style="display:flex;flex-direction:column;gap:4px;align-items:flex-end;">
        <div style="display:flex;gap:10px;align-items:center;">
            <?php if ($total > 0): ?>
                <span class="badge"><?= number_format($total, 0, ',', '.') ?> publicaciones</span>
            <?php endif; ?>
            <a href="/remates_vehiculos.php"
               style="color:#fff;text-decoration:none;font-size:.82rem;padding:5px 14px;border:1px solid rgba(124,58,237,.6);border-radius:5px;background:rgba(124,58,237,.3);font-weight:600;">
                🚗 Mis Remates
            </a>
            <button id="btn-sync" onclick="runSync(false)">↻ Actualizar</button>
            <button id="btn-full-sync" onclick="runSync(true)"
                    style="color:#fff;font-size:.75rem;padding:4px 10px;border:1px solid rgba(255,255,255,.25);border-radius:5px;background:rgba(255,255,255,.07);font-weight:500;cursor:pointer;">
                ↻ Sync completo
            </button>
        </div>
        <div id="sync-result"></div>
    </nav>
</header>

<main>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <strong>Error al consultar la base de datos:</strong> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!$error && $dbEmpty): ?>
        <div class="alert alert-info">
            <strong>Base de datos vacía.</strong>
            Haz clic en <strong>↻ Actualizar</strong> para sincronizar los registros del portal por primera vez.
            Este proceso puede tardar algunos minutos.
        </div>
    <?php elseif (!$error && $total === 0 && !$dbEmpty): ?>
        <div class="alert alert-info">
            No hay registros desde octubre de 2025 en la base de datos.
            Haz clic en <strong>↻ Actualizar</strong> para sincronizar.
        </div>
    <?php endif; ?>

    <?php if (!$error && $total > 0): ?>

        <form method="get" action="" style="margin-bottom:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="orden" value="<?= htmlspecialchars($orden) ?>">
            <?php if ($pendientes): ?><input type="hidden" name="pendientes" value="1"><?php endif; ?>
            <input
                type="text"
                name="buscar"
                value="<?= htmlspecialchars($buscar) ?>"
                placeholder="Buscar por nombre de publicación…"
                style="flex:1;min-width:240px;max-width:480px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.875rem;font-family:inherit;transition:border-color 0.15s;"
                onfocus="this.style.borderColor='#0f3460';this.style.boxShadow='0 0 0 3px rgba(15,52,96,0.1)'"
                onblur="this.style.borderColor='#d1d5db';this.style.boxShadow='none'"
            >
            <button type="submit" class="btn btn-primary" style="white-space:nowrap;">🔍 Buscar</button>
            <?php if ($buscar !== ''): ?>
                <a href="<?= htmlspecialchars(pageUrl(1, $orden, $pendientes)) ?>" class="btn" style="background:#f3f4f6;color:#555;border:1px solid #d1d5db;white-space:nowrap;">✕ Limpiar</a>
            <?php endif; ?>
        </form>

        <div class="stats-bar">
            <div class="stats-info">
                Mostrando <strong><?= $from ?>–<?= $to ?></strong> de
                <strong><?= number_format($total, 0, ',', '.') ?></strong>
                <?= $pendientes ? 'pendientes' : 'publicaciones' ?>
                &nbsp;·&nbsp; Página <?= $page ?> de <?= $totalPages ?>
                &nbsp;·&nbsp; <span title="Tiempo de consulta BD">⏱ <?= $elapsed ?>s</span>
                &nbsp;·&nbsp;
                <span style="font-size:.8rem;font-weight:600;color:<?= $orden === 'asc' ? '#2d8a4e' : '#0f3460' ?>">
                    <?= $orden === 'asc' ? '↑ Más antiguo primero' : '↓ Más reciente primero' ?>
                </span>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <?php
                $toggleOrden = $orden === 'asc' ? 'desc' : 'asc';
                $toggleLabel = $orden === 'asc' ? '↓ Más reciente primero' : '↑ Más antiguo primero';
                ?>
                <a href="<?= htmlspecialchars(pageUrl(1, $toggleOrden, $pendientes, $buscar)) ?>" class="btn btn-primary">
                    <?= $toggleLabel ?>
                </a>
                <?php if ($pendientes): ?>
                    <a href="<?= htmlspecialchars(pageUrl(1, $orden, false, $buscar)) ?>"
                       class="btn" style="background:#f3f4f6;color:#555;border:1px solid #d1d5db;">
                        ✕ Ver todas
                    </a>
                <?php else: ?>
                    <a href="<?= htmlspecialchars(pageUrl(1, $orden, true, $buscar)) ?>"
                       class="btn" style="background:#fff8e1;color:#78350f;border:1px solid #f59e0b;">
                        ☐ Solo pendientes
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Título / Proceso</th>
                        <th>Despacho / Juzgado</th>
                        <th>Fecha</th>
                        <th>Detalle</th>
                        <th title="Agregar remates de vehículo encontrados">Agregar Remate</th>
                        <th title="Marca cuando ya investigaste este registro">Procesado</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $i => $item):
                        $aid     = $item['article_id'];
                        $checked = $aid && isset($procesadosSet[$aid]);
                        $cnt     = $aid ? ($rematesCount[$aid] ?? 0) : 0;
                    ?>
                    <tr class="<?= $checked ? 'is-procesado' : '' ?>" data-aid="<?= htmlspecialchars($aid) ?>">
                        <td><?= $from + $i ?></td>
                        <td class="titulo-cell"><?= htmlspecialchars($item['titulo']) ?></td>
                        <td class="despacho-cell"><?= htmlspecialchars($item['despacho']) ?></td>
                        <td class="fecha-cell"><?= htmlspecialchars($item['fecha']) ?></td>
                        <td class="link-cell">
                            <?php if ($item['enlace']): ?>
                                <a href="<?= htmlspecialchars($item['enlace']) ?>"
                                   target="_blank" rel="noopener"
                                   class="btn-detail">Ver ↗</a>
                            <?php endif; ?>
                        </td>
                        <td class="remate-cell">
                            <?php if ($aid): ?>
                                <button class="btn-add-remate"
                                        onclick="openModal('<?= htmlspecialchars($aid) ?>', <?= htmlspecialchars(json_encode($item['titulo'])) ?>)"
                                        data-aid="<?= htmlspecialchars($aid) ?>">
                                    +
                                    <?php if ($cnt > 0): ?>
                                        <span class="cnt-badge" data-cnt="<?= $aid ?>"><?= $cnt ?></span>
                                    <?php else: ?>
                                        <span class="cnt-badge" data-cnt="<?= $aid ?>" style="display:none">0</span>
                                    <?php endif; ?>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td class="procesado-cell">
                            <?php if ($aid): ?>
                                <span class="procesado-label <?= $checked ? 'checked' : '' ?>"
                                      onclick="toggleProcesado(this)"
                                      data-aid="<?= htmlspecialchars($aid) ?>"
                                      title="<?= $checked ? 'Marcar como no procesado' : 'Marcar como procesado' ?>">
                                    <?= $checked ? '✓' : '' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Paginación">
                <?php
                if ($page > 1) {
                    echo '<a href="' . htmlspecialchars(pageUrl(1, $orden, $pendientes, $buscar)) . '" title="Primera">«</a>';
                    echo '<a href="' . htmlspecialchars(pageUrl($page - 1, $orden, $pendientes, $buscar)) . '" title="Anterior">‹</a>';
                } else {
                    echo '<span class="disabled">«</span><span class="disabled">‹</span>';
                }
                $window = 2; $start = max(1, $page - $window); $end = min($totalPages, $page + $window);
                if ($start > 1) {
                    echo '<a href="' . htmlspecialchars(pageUrl(1, $orden, $pendientes, $buscar)) . '">1</a>';
                    if ($start > 2) echo '<span class="ellipsis">…</span>';
                }
                for ($p = $start; $p <= $end; $p++) {
                    if ($p === $page) echo '<span class="current">' . $p . '</span>';
                    else echo '<a href="' . htmlspecialchars(pageUrl($p, $orden, $pendientes, $buscar)) . '">' . $p . '</a>';
                }
                if ($end < $totalPages) {
                    if ($end < $totalPages - 1) echo '<span class="ellipsis">…</span>';
                    echo '<a href="' . htmlspecialchars(pageUrl($totalPages, $orden, $pendientes, $buscar)) . '">' . $totalPages . '</a>';
                }
                if ($page < $totalPages) {
                    echo '<a href="' . htmlspecialchars(pageUrl($page + 1, $orden, $pendientes, $buscar)) . '" title="Siguiente">›</a>';
                    echo '<a href="' . htmlspecialchars(pageUrl($totalPages, $orden, $pendientes, $buscar)) . '" title="Última">»</a>';
                } else {
                    echo '<span class="disabled">›</span><span class="disabled">»</span>';
                }
                ?>
            </nav>
        <?php endif; ?>

    <?php endif; ?>

</main>

<footer>
    Datos obtenidos de
    <a href="https://publicacionesprocesales.ramajudicial.gov.co" target="_blank" rel="noopener">
        publicacionesprocesales.ramajudicial.gov.co
    </a>
    · Rama Judicial de Colombia
</footer>

<!-- ── Modal: Agregar / Ver Remates de Vehículo ───────────────────────────── -->
<div class="modal-overlay" id="modal-overlay" onclick="overlayClick(event)">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-header">
            <div class="modal-title">
                <h2>Remates de Vehículo</h2>
                <p id="modal-pub-titulo"></p>
            </div>
            <button class="modal-close" onclick="closeModal()" title="Cerrar">×</button>
        </div>

        <div class="modal-body">
            <!-- Existing remates -->
            <div class="remates-list" id="remates-list">
                <h3>Remates registrados</h3>
                <div id="remates-list-body">
                    <p class="no-remates-msg">Cargando…</p>
                </div>
            </div>

            <!-- Form -->
            <hr class="form-divider">
            <p class="form-section-title">Agregar nuevo remate</p>

            <form id="form-remate" onsubmit="submitRemate(event)">
                <input type="hidden" id="f-article-id" name="article_id">
                <input type="hidden" id="f-titulo-pub" name="titulo_pub">

                <div class="form-grid cols-1" style="margin-bottom:14px">
                    <div class="form-group">
                        <label>Número de Radicado <span class="req">*</span></label>
                        <input type="text" id="f-radicado" name="radicado"
                               placeholder="Ej. 11001310303020200039300" autocomplete="off">
                    </div>
                </div>

                <div class="form-grid cols-3" style="margin-bottom:14px">
                    <div class="form-group">
                        <label>Marca</label>
                        <input type="text" name="marca" placeholder="Ej. Chevrolet">
                    </div>
                    <div class="form-group">
                        <label>Línea</label>
                        <input type="text" name="modelo" placeholder="Ej. Spark">
                    </div>
                    <div class="form-group">
                        <label>Modelo</label>
                        <input type="text" name="anio" placeholder="Ej. 2018" maxlength="4">
                    </div>
                </div>

                <div class="form-grid cols-2" style="margin-bottom:14px">
                    <div class="form-group">
                        <label>Placa</label>
                        <input type="text" name="placa" placeholder="Ej. ABC123" maxlength="10" style="text-transform:uppercase">
                    </div>
                    <div class="form-group">
                        <label>Color</label>
                        <input type="text" name="color" placeholder="Ej. Rojo">
                    </div>
                </div>

                <div class="form-grid cols-2" style="margin-bottom:14px">
                    <div class="form-group">
                        <label>Avalúo</label>
                        <input type="text" name="avaluo" id="f-avaluo" oninput="calcBaseModal()" placeholder="Ej. 25000000">
                    </div>
                    <div class="form-group">
                        <label>Base de Remate <span style="color:#7c3aed;font-size:.68rem">(auto 70%)</span></label>
                        <input type="text" name="base_remate" id="f-base-remate" placeholder="Se calcula automáticamente">
                    </div>
                </div>

                <div class="form-grid cols-3" style="margin-bottom:14px">
                    <div class="form-group">
                        <label>Fecha Audiencia</label>
                        <input type="date" name="fecha_remate">
                    </div>
                    <div class="form-group">
                        <label>Hora</label>
                        <input type="time" name="hora_remate">
                    </div>
                    <div class="form-group">
                        <label>Modalidad</label>
                        <select name="modalidad">
                            <option value="">— Seleccionar —</option>
                            <option value="Virtual">Virtual</option>
                            <option value="Presencial">Presencial</option>
                        </select>
                    </div>
                </div>

                <div class="form-grid cols-1">
                    <div class="form-group">
                        <label>Notas / Observaciones</label>
                        <textarea name="notas" placeholder="Información adicional del remate…"></textarea>
                    </div>
                </div>
            </form>
        </div>

        <div class="modal-footer">
            <button class="btn-cancel" onclick="closeModal()">Cancelar</button>
            <button class="btn-save" onclick="document.getElementById('form-remate').requestSubmit()">
                Guardar Remate
            </button>
        </div>
    </div>
</div>

<script>
// ── Sync ──────────────────────────────────────────────────────────────────────
async function runSync(full = false) {
    const btn     = document.getElementById('btn-sync');
    const btnFull = document.getElementById('btn-full-sync');
    const result  = document.getElementById('sync-result');

    btn.disabled     = true;
    btnFull.disabled = true;
    btn.textContent  = full ? '↻ Actualizar' : 'Sincronizando…';
    btnFull.textContent = full ? 'Sincronizando…' : '↻ Sync completo';
    result.textContent  = full
        ? 'Sincronización completa, puede tardar varios minutos…'
        : 'Buscando nuevas publicaciones…';

    try {
        const url = full ? '/sync.php?full=1' : '/sync.php';
        const r   = await fetch(url);
        const d   = await r.json();
        if (d.ok) {
            result.textContent = `+${d.inserted} nuevos · ${d.updated} actualizados · ${d.pages} págs. escaneadas`;
            btn.textContent     = '✓ Listo';
            btnFull.textContent = '↻ Sync completo';
            setTimeout(() => location.reload(), 1800);
        } else {
            result.textContent  = 'Error: ' + (d.error || 'desconocido');
            btn.textContent     = '↻ Actualizar';
            btnFull.textContent = '↻ Sync completo';
            btn.disabled = btnFull.disabled = false;
        }
    } catch (e) {
        result.textContent  = 'Error de conexión.';
        btn.textContent     = '↻ Actualizar';
        btnFull.textContent = '↻ Sync completo';
        btn.disabled = btnFull.disabled = false;
    }
}

// ── Toggle procesado ──────────────────────────────────────────────────────────
function formatCOP(val) {
    if (!val) return '';
    const num = parseInt(String(val).replace(/[^\d]/g, ''), 10);
    if (isNaN(num)) return val;
    return '$ ' + num.toLocaleString('es-CO');
}

function calcBase(avaluoInput, baseFieldId) {
    const raw = String(avaluoInput.value).replace(/[^\d]/g, '');
    const num = parseInt(raw, 10);
    const baseInput = document.getElementById(baseFieldId);
    if (baseInput && !isNaN(num) && num > 0) {
        baseInput.value = Math.round(num * 0.7);
    } else if (baseInput && (raw === '' || num === 0)) {
        baseInput.value = '';
    }
}

function calcBaseModal() {
    const avaluoInput = document.getElementById('f-avaluo');
    if (avaluoInput) calcBase(avaluoInput, 'f-base-remate');
}

async function toggleProcesado(label) {
    const aid = label.dataset.aid;
    if (!aid) return;
    const willCheck = !label.classList.contains('checked');
    label.classList.toggle('checked', willCheck);
    label.textContent = willCheck ? '✓' : '';
    label.title = willCheck ? 'Marcar como no procesado' : 'Marcar como procesado';
    const row = label.closest('tr');
    row.classList.toggle('is-procesado', willCheck);
    try {
        const fd = new FormData();
        fd.append('action', 'toggle_procesado');
        fd.append('article_id', aid);
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await r.json();
        label.classList.toggle('checked', d.procesado);
        label.textContent = d.procesado ? '✓' : '';
        row.classList.toggle('is-procesado', d.procesado);
    } catch (e) {
        label.classList.toggle('checked', !willCheck);
        label.textContent = !willCheck ? '✓' : '';
        row.classList.toggle('is-procesado', !willCheck);
    }
}

// ── Modal ─────────────────────────────────────────────────────────────────────
let currentAid    = null;
let currentTitulo = '';
const cardDataCache = {};

function openModal(aid, titulo) {
    currentAid    = aid;
    currentTitulo = titulo;
    document.getElementById('modal-pub-titulo').textContent = titulo;
    document.getElementById('f-article-id').value  = aid;
    document.getElementById('f-titulo-pub').value  = titulo;
    document.getElementById('form-remate').reset();
    document.getElementById('f-article-id').value  = aid;
    document.getElementById('f-titulo-pub').value  = titulo;
    document.getElementById('f-radicado').classList.remove('invalid');
    document.getElementById('modal-overlay').classList.add('open');
    loadRemates(aid);
}

function closeModal() {
    document.getElementById('modal-overlay').classList.remove('open');
    currentAid = null;
}

function overlayClick(e) {
    if (e.target === document.getElementById('modal-overlay')) closeModal();
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

async function loadRemates(aid) {
    const body = document.getElementById('remates-list-body');
    body.innerHTML = '<p class="no-remates-msg">Cargando…</p>';
    try {
        const r = await fetch('?action=get_remates&aid=' + encodeURIComponent(aid));
        const list = await r.json();
        list.forEach(item => { cardDataCache[item.id] = item; });
        renderRemates(list, aid);
    } catch (e) {
        body.innerHTML = '<p class="no-remates-msg" style="color:#e94560">Error al cargar.</p>';
    }
}

function renderRemates(list, aid) {
    const body = document.getElementById('remates-list-body');
    if (!list.length) {
        body.innerHTML = '<p class="no-remates-msg">Sin remates registrados aún.</p>';
        updateBadge(aid, 0);
        return;
    }
    updateBadge(aid, list.length);
    body.innerHTML = list.map(r => cardViewHtml(r, aid)).join('');
}

function cardViewHtml(r, aid) {
    return `
    <div class="remate-card view-mode" id="rc-${r.id}" onclick="editRemate(event, ${r.id}, '${esc(aid)}')">
        <div>
            <div class="rc-radicado">📋 ${esc(r.radicado)}</div>
            <div class="rc-details">
                ${r.marca       ? `<span>🚗 ${esc(r.marca)} ${esc(r.modelo||'')} ${esc(r.anio||'')}</span>` : ''}
                ${r.placa       ? `<span>🔖 ${esc(r.placa)}</span>` : ''}
                ${r.color       ? `<span>🎨 ${esc(r.color)}</span>` : ''}
                ${r.avaluo      ? `<span>💰 Avalúo: ${formatCOP(r.avaluo)}</span>` : ''}
                ${r.base_remate ? `<span>🏷️ Base: ${formatCOP(r.base_remate)}</span>` : ''}
                ${r.fecha_remate? `<span>📅 ${esc(r.fecha_remate)}${r.hora_remate?' '+esc(r.hora_remate):''}</span>` : ''}
                ${r.modalidad   ? `<span>📍 ${esc(r.modalidad)}</span>` : ''}
            </div>
            ${r.notas ? `<div class="rc-notas">${esc(r.notas)}</div>` : ''}
            <div class="rc-edit-hint">✏ Clic para editar</div>
        </div>
        <div onclick="event.stopPropagation()">
            <button class="btn-delete-remate" onclick="deleteRemate(${r.id}, '${esc(aid)}')">Eliminar</button>
        </div>
    </div>`;
}

function cardEditHtml(r, aid) {
    return `
    <div class="remate-card edit-mode" id="rc-${r.id}">
        <div>
            <div style="font-size:.8rem;font-weight:700;color:#7c3aed;margin-bottom:4px">✏ Editando remate</div>

            <div class="edit-form-grid cols-1">
                <div class="form-group">
                    <label>Número de Radicado <span class="req">*</span></label>
                    <input type="text" id="ef-radicado-${r.id}" value="${esc(r.radicado)}" placeholder="Ej. 11001310303020200039300">
                </div>
            </div>
            <div class="edit-form-grid cols-3">
                <div class="form-group"><label>Marca</label><input type="text" id="ef-marca-${r.id}" value="${esc(r.marca||'')}"></div>
                <div class="form-group"><label>Línea</label><input type="text" id="ef-modelo-${r.id}" value="${esc(r.modelo||'')}"></div>
                <div class="form-group"><label>Modelo</label><input type="text" id="ef-anio-${r.id}" value="${esc(r.anio||'')}" maxlength="4"></div>
            </div>
            <div class="edit-form-grid cols-2">
                <div class="form-group"><label>Placa</label><input type="text" id="ef-placa-${r.id}" value="${esc(r.placa||'')}" style="text-transform:uppercase" maxlength="10"></div>
                <div class="form-group"><label>Color</label><input type="text" id="ef-color-${r.id}" value="${esc(r.color||'')}"></div>
            </div>
            <div class="edit-form-grid cols-2">
                <div class="form-group">
                    <label>Avalúo</label>
                    <input id="ef-avaluo-${r.id}" value="${esc(r.avaluo||'')}" oninput="calcBase(this, 'ef-base-${r.id}')" placeholder="Ej. 25000000">
                </div>
                <div class="form-group">
                    <label>Base de Remate <span style="color:#7c3aed;font-size:.68rem">(auto 70%)</span></label>
                    <input id="ef-base-${r.id}" value="${esc(r.base_remate||'')}">
                </div>
            </div>
            <div class="edit-form-grid cols-3">
                <div class="form-group"><label>Fecha Audiencia</label><input type="date" id="ef-fecha-${r.id}" value="${esc(r.fecha_remate||'')}"></div>
                <div class="form-group"><label>Hora</label><input type="time" id="ef-hora-${r.id}" value="${esc(r.hora_remate||'')}"></div>
                <div class="form-group"><label>Modalidad</label>
                    <select id="ef-modalidad-${r.id}">
                        <option value="">— Seleccionar —</option>
                        <option value="Virtual" ${r.modalidad==='Virtual'?'selected':''}>Virtual</option>
                        <option value="Presencial" ${r.modalidad==='Presencial'?'selected':''}>Presencial</option>
                    </select>
                </div>
            </div>
            <div class="edit-form-grid cols-1">
                <div class="form-group"><label>Notas</label><textarea id="ef-notas-${r.id}">${esc(r.notas||'')}</textarea></div>
            </div>

            <div class="edit-actions">
                <button class="btn-cancel-edit" onclick="cancelEdit(${r.id}, '${esc(aid)}')">Cancelar</button>
                <button class="btn-save-edit" onclick="saveEdit(${r.id}, '${esc(aid)}')">Guardar cambios</button>
            </div>
        </div>
    </div>`;
}

function editRemate(event, id, aid) {
    if (event.target.classList.contains('btn-delete-remate')) return;
    const card = document.getElementById('rc-' + id);
    if (!card || card.classList.contains('edit-mode')) return;
    const data = cardDataCache[id];
    if (!data) return;
    card.outerHTML = cardEditHtml(data, aid);
}

function cancelEdit(id, aid) {
    const data = cardDataCache[id];
    if (!data) return;
    document.getElementById('rc-' + id).outerHTML = cardViewHtml(data, aid);
}

async function saveEdit(id, aid) {
    const radicadoInput = document.getElementById('ef-radicado-' + id);
    if (!radicadoInput.value.trim()) {
        radicadoInput.classList.add('invalid');
        radicadoInput.focus();
        return;
    }
    const fd = new FormData();
    fd.append('action',       'update_remate');
    fd.append('id',            id);
    fd.append('article_id',    aid);
    fd.append('radicado',      radicadoInput.value.trim());
    fd.append('marca',         document.getElementById('ef-marca-'     + id).value.trim());
    fd.append('modelo',        document.getElementById('ef-modelo-'    + id).value.trim());
    fd.append('anio',          document.getElementById('ef-anio-'      + id).value.trim());
    fd.append('placa',         document.getElementById('ef-placa-'     + id).value.trim().toUpperCase());
    fd.append('color',         document.getElementById('ef-color-'     + id).value.trim());
    fd.append('avaluo',        document.getElementById('ef-avaluo-'    + id).value.trim());
    fd.append('base_remate',   document.getElementById('ef-base-'      + id).value.trim());
    fd.append('fecha_remate',  document.getElementById('ef-fecha-'     + id).value.trim());
    fd.append('hora_remate',   document.getElementById('ef-hora-'      + id).value.trim());
    fd.append('modalidad',     document.getElementById('ef-modalidad-' + id).value);
    fd.append('notas',         document.getElementById('ef-notas-'     + id).value.trim());

    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.error) { alert('Error: ' + d.error); return; }
        cardDataCache[id] = d.remate;
        document.getElementById('rc-' + id).outerHTML = cardViewHtml(d.remate, aid);
    } catch(e) {
        alert('Error de conexión.');
    }
}

function updateBadge(aid, cnt) {
    document.querySelectorAll(`[data-cnt="${CSS.escape(aid)}"]`).forEach(el => {
        el.textContent = cnt;
        el.style.display = cnt > 0 ? '' : 'none';
    });
}

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function submitRemate(e) {
    e.preventDefault();
    const radicadoInput = document.getElementById('f-radicado');
    if (!radicadoInput.value.trim()) {
        radicadoInput.classList.add('invalid');
        radicadoInput.focus();
        return;
    }
    radicadoInput.classList.remove('invalid');

    const fd = new FormData(document.getElementById('form-remate'));
    fd.append('action', 'save_remate');

    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.error) { alert('Error: ' + d.error); return; }
        cardDataCache[d.remate.id] = d.remate;
        document.getElementById('form-remate').reset();
        document.getElementById('f-article-id').value  = currentAid;
        document.getElementById('f-titulo-pub').value  = currentTitulo;
        await loadRemates(currentAid);
    } catch (err) {
        alert('Error de conexión. Intenta de nuevo.');
    }
}

async function deleteRemate(id, aid) {
    if (!confirm('¿Eliminar este remate?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_remate');
    fd.append('id', id);
    fd.append('article_id', aid);
    try {
        await fetch('', { method: 'POST', body: fd });
        await loadRemates(aid);
    } catch (e) {
        alert('Error al eliminar.');
    }
}
</script>
</body>
</html>
