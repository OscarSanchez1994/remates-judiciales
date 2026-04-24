<?php
// ── DB ────────────────────────────────────────────────────────────────────────
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

// ── Ensure estado column exists ────────────────────────────────────────────────
try { db()->exec("ALTER TABLE remates_vehiculos ADD COLUMN estado VARCHAR(20) NOT NULL DEFAULT ''"); } catch(Exception $e) {}

// ── AJAX: set_estado ──────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'set_estado') {
    header('Content-Type: application/json');
    $id     = (int) ($_POST['id']   ?? 0);
    $estado = trim($_POST['estado'] ?? '');
    $motivo = trim($_POST['motivo'] ?? '');
    if (!$id) { echo json_encode(['error' => 'invalid']); exit; }
    if ($motivo !== '') {
        $s = db()->prepare('SELECT notas FROM remates_vehiculos WHERE id = ?');
        $s->execute([$id]);
        $existing = (string) ($s->fetchColumn() ?? '');
        $newNotas = $existing !== '' ? $existing . "\n" . $motivo : $motivo;
        db()->prepare('UPDATE remates_vehiculos SET estado=?, notas=? WHERE id=?')->execute([$estado, $newNotas, $id]);
        echo json_encode(['ok' => true, 'notas' => $newNotas]);
    } else {
        db()->prepare('UPDATE remates_vehiculos SET estado=? WHERE id=?')->execute([$estado, $id]);
        echo json_encode(['ok' => true, 'notas' => null]);
    }
    exit;
}

// ── AJAX: update ──────────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'update') {
    header('Content-Type: application/json');
    $id       = (int) ($_POST['id'] ?? 0);
    $radicado = trim($_POST['radicado'] ?? '');
    if (!$id || !$radicado) { echo json_encode(['error' => 'Radicado es obligatorio']); exit; }
    db()->prepare('
        UPDATE remates_vehiculos SET
            radicado=?, marca=?, modelo=?, anio=?, placa=?, color=?,
            avaluo=?, base_remate=?, fecha_remate=?, hora_remate=?, modalidad=?, notas=?
        WHERE id=?
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
        $id,
    ]);
    $row = db()->prepare('SELECT * FROM remates_vehiculos WHERE id = ?');
    $row->execute([$id]);
    echo json_encode(['ok' => true, 'remate' => $row->fetch(PDO::FETCH_ASSOC)]);
    exit;
}

// ── AJAX: delete ──────────────────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    header('Content-Type: application/json');
    $id = (int) ($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['error' => 'invalid']); exit; }
    db()->prepare('DELETE FROM remates_vehiculos WHERE id = ?')->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── CSV export ────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $q      = trim($_GET['q']         ?? '');
    $marca  = trim($_GET['marca']     ?? '');
    $modal  = trim($_GET['modalidad'] ?? '');
    $fd     = trim($_GET['fd']        ?? '');
    $fh     = trim($_GET['fh']        ?? '');

    [$where, $params] = buildWhere($q, $marca, $modal, $fd, $fh);
    $stmt = db()->prepare("SELECT * FROM remates_vehiculos $where ORDER BY id DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="remates-vehiculos-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['#','Radicado','Publicación','Marca','Línea','Modelo','Placa','Color','Avalúo','Base Remate','Fecha','Hora','Modalidad','Notas'], ';');
    foreach ($rows as $i => $r) {
        fputcsv($out, [$i+1, $r['radicado'], $r['titulo_pub'], $r['marca'], $r['modelo'],
            $r['anio'], $r['placa'], $r['color'], $r['avaluo'], $r['base_remate'],
            $r['fecha_remate'], $r['hora_remate'], $r['modalidad'], $r['notas']], ';');
    }
    fclose($out); exit;
}

// ── Build WHERE clause ────────────────────────────────────────────────────────
function buildWhere(string $q, string $marca, string $modal, string $fd, string $fh): array {
    $conds = []; $params = [];
    if ($q) {
        $conds[]  = '(radicado LIKE ? OR placa LIKE ? OR titulo_pub LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    if ($marca)  { $conds[] = 'marca LIKE ?';     $params[] = '%' . $marca . '%'; }
    if ($modal)  { $conds[] = 'modalidad = ?';    $params[] = $modal; }
    if ($fd)     { $conds[] = 'fecha_remate >= ?'; $params[] = $fd; }
    if ($fh)     { $conds[] = 'fecha_remate <= ?'; $params[] = $fh; }
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
    return [$where, $params];
}

// ── Currency display helper ────────────────────────────────────────────────────
function formatCOP(?string $val): string {
    if ($val === null || $val === '') return '';
    $num = preg_replace('/[^\d]/', '', $val);
    if ($num === '') return $val;
    return '$ ' . number_format((int)$num, 0, ',', '.');
}

// ── Filters & pagination ──────────────────────────────────────────────────────
$q         = trim($_GET['q']         ?? '');
$marca     = trim($_GET['marca']     ?? '');
$modal     = trim($_GET['modalidad'] ?? '');
$fd        = trim($_GET['fd']        ?? '');
$fh        = trim($_GET['fh']        ?? '');
$page      = max(1, (int) ($_GET['pagina'] ?? 1));
$perPage   = 20;

// Sort params
$sortAllow = ['avaluo' => 'rv.avaluo+0', 'fecha' => 'rv.fecha_remate, rv.hora_remate'];
$sortKey   = isset($_GET['sort'], $sortAllow[$_GET['sort']]) ? $_GET['sort'] : '';
$sortDir   = ($_GET['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';

[$where, $params] = buildWhere($q, $marca, $modal, $fd, $fh);

$total = (int) db()->prepare("SELECT COUNT(*) FROM remates_vehiculos $where")
    ->execute($params) ? db()->prepare("SELECT COUNT(*) FROM remates_vehiculos $where")
    ->execute($params) || true : 0;

// Re-run properly
$cntStmt = db()->prepare("SELECT COUNT(*) FROM remates_vehiculos $where");
$cntStmt->execute($params);
$total = (int) $cntStmt->fetchColumn();

$totalPages = max(1, (int) ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$orderBy = $sortKey ? $sortAllow[$sortKey] . ' ' . $sortDir . ', rv.id DESC' : 'rv.id DESC';
$stmt = db()->prepare("SELECT rv.*, p.enlace AS pub_enlace, p.titulo AS pub_titulo FROM remates_vehiculos rv LEFT JOIN publicaciones p ON p.article_id = rv.article_id $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Current filter query string (for pagination links)
function filterQs(array $extra = []): string {
    global $q, $marca, $modal, $fd, $fh, $sortKey, $sortDir;
    $base = array_filter(['q'=>$q,'marca'=>$marca,'modalidad'=>$modal,'fd'=>$fd,'fh'=>$fh]);
    if ($sortKey && !isset($extra['sort'])) {
        $base['sort'] = $sortKey;
        $base['dir']  = strtolower($sortDir);
    }
    return '?' . http_build_query(array_merge($base, $extra));
}

function sortUrl(string $key): string {
    global $sortKey, $sortDir;
    if ($sortKey !== $key)      return filterQs(['sort'=>$key, 'dir'=>'asc',  'pagina'=>1]);
    if ($sortDir === 'ASC')     return filterQs(['sort'=>$key, 'dir'=>'desc', 'pagina'=>1]);
    return filterQs(['sort'=>'', 'dir'=>'', 'pagina'=>1]);
}

function sortIcon(string $key): string {
    global $sortKey, $sortDir;
    if ($sortKey !== $key) return '<span class="sort-icon">⇅</span>';
    return $sortDir === 'ASC'
        ? '<span class="sort-icon active">↑</span>'
        : '<span class="sort-icon active">↓</span>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis RematesCO</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f0f2f5; color: #1a1a2e; min-height: 100vh; }

        /* Header */
        header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            color: #fff; padding: 20px 32px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap; box-shadow: 0 2px 12px rgba(0,0,0,.3);
        }
        header .brand { display: flex; align-items: center; gap: 12px; }
        header h1 { font-size: 1.3rem; font-weight: 700; }
        header p.subtitle { font-size: .82rem; color: #a0c4ff; margin-top: 2px; }
        .badge { background: #7c3aed; color: #fff; padding: 4px 12px; border-radius: 20px; font-size: .85rem; font-weight: 600; }
        .nav-link { color: #a0c4ff; text-decoration: none; font-size: .82rem; padding: 5px 12px; border: 1px solid rgba(160,196,255,.3); border-radius: 5px; transition: background .15s; }
        .nav-link:hover { background: rgba(255,255,255,.1); }

        /* Main */
        main { max-width: 1400px; margin: 0 auto; padding: 24px 16px; }

        /* Filter card */
        .filter-card {
            background: #fff; border-radius: 10px; padding: 18px 20px;
            box-shadow: 0 1px 8px rgba(0,0,0,.07); margin-bottom: 20px;
        }
        .filter-card h2 { font-size: .8rem; font-weight: 700; color: #0f3460; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 14px; }
        .filter-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto; gap: 10px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        .filter-group label { font-size: .72rem; font-weight: 600; color: #555; text-transform: uppercase; letter-spacing: .3px; }
        .filter-group input, .filter-group select {
            border: 1px solid #d1d5db; border-radius: 6px; padding: 8px 10px;
            font-size: .875rem; font-family: inherit;
        }
        .filter-group input:focus, .filter-group select:focus { outline: none; border-color: #7c3aed; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: .875rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: all .15s; }
        .btn-purple { background: #7c3aed; color: #fff; }
        .btn-purple:hover { background: #6d28d9; }
        .btn-ghost { background: #f3f4f6; color: #555; }
        .btn-ghost:hover { background: #e5e7eb; }
        .btn-green { background: #2d8a4e; color: #fff; }
        .btn-green:hover { background: #246b3d; }

        /* Stats bar */
        .stats-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 16px; font-size: .85rem; color: #555; }
        .stats-bar strong { color: #0f3460; }

        /* Table */
        .table-wrapper { background: #fff; border-radius: 10px; overflow-x: auto; box-shadow: 0 1px 8px rgba(0,0,0,.08); }
        table { width: 100%; border-collapse: collapse; font-size: .82rem; min-width: 900px; }
        thead tr { background: #0f3460; color: #fff; }
        thead th { padding: 11px 12px; text-align: left; font-weight: 600; font-size: .75rem; text-transform: uppercase; letter-spacing: .5px; white-space: nowrap; }
        thead th:first-child { width: 40px; text-align: center; }
        tbody tr { border-bottom: 1px solid #eef0f5; transition: background .1s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover:not(.edit-row) { background: #f8f5ff; }
        td { padding: 11px 12px; vertical-align: middle; }
        td:first-child { text-align: center; color: #999; font-size: .78rem; }
        .td-radicado { font-weight: 700; color: #0f3460; font-size: .68rem; white-space: nowrap; }
        .td-pub { color: #666; font-size: .78rem; max-width: 180px; line-height: 1.3; }
        .td-auto { font-size: .82rem; color: #333; }
        .td-placa { font-family: monospace; font-weight: 700; font-size: .85rem; }
        .td-fecha { white-space: nowrap; font-size: .78rem; color: #555; }
        .td-notas { font-size: .75rem; color: #777; max-width: 150px; font-style: italic; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: .72rem; font-weight: 600; }
        .pill-virtual    { background: #dbeafe; color: #1e40af; }
        .pill-presencial { background: #d1fae5; color: #065f46; }

        /* Action buttons */
        .action-btns { display: flex; gap: 6px; }
        .btn-edit { background: none; border: 1px solid #7c3aed; color: #7c3aed; border-radius: 5px; padding: 4px 10px; font-size: .75rem; cursor: pointer; white-space: nowrap; transition: all .15s; }
        .btn-edit:hover { background: #7c3aed; color: #fff; }
        .btn-del  { background: none; border: 1px solid #e94560; color: #e94560; border-radius: 5px; padding: 4px 10px; font-size: .75rem; cursor: pointer; white-space: nowrap; transition: all .15s; }
        .btn-del:hover  { background: #e94560; color: #fff; }

        /* Inline edit row */
        tr.edit-row td { background: #faf5ff; padding: 14px 12px; vertical-align: top; }
        .edit-row-form { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
        .edit-row-form .fg { display: flex; flex-direction: column; gap: 3px; }
        .edit-row-form .fg label { font-size: .68rem; font-weight: 700; color: #555; text-transform: uppercase; }
        .edit-row-form .fg input,
        .edit-row-form .fg select,
        .edit-row-form .fg textarea { border: 1px solid #c4b5fd; border-radius: 5px; padding: 5px 8px; font-size: .8rem; font-family: inherit; }
        .edit-row-form .fg input:focus,
        .edit-row-form .fg select:focus,
        .edit-row-form .fg textarea:focus { outline: none; border-color: #7c3aed; box-shadow: 0 0 0 2px rgba(124,58,237,.15); }
        .edit-row-form .fg.full { grid-column: 1 / -1; }
        .edit-row-form .fg textarea { min-height: 60px; resize: vertical; }
        .edit-save-bar { display: flex; gap: 8px; margin-top: 12px; }
        .btn-save-inline { background: #7c3aed; color: #fff; border: none; border-radius: 5px; padding: 6px 18px; font-size: .8rem; font-weight: 700; cursor: pointer; }
        .btn-save-inline:hover { background: #6d28d9; }
        .btn-cancel-inline { background: #f3f4f6; color: #555; border: none; border-radius: 5px; padding: 6px 14px; font-size: .8rem; cursor: pointer; }
        .btn-cancel-inline:hover { background: #e5e7eb; }
        .input-invalid { border-color: #e94560 !important; }

        /* Pagination */
        .pagination { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 24px; flex-wrap: wrap; }
        .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 0 8px; border-radius: 6px; font-size: .85rem; font-weight: 500; text-decoration: none; }
        .pagination a { background: #fff; color: #0f3460; border: 1px solid #dce3f0; transition: all .15s; }
        .pagination a:hover { background: #7c3aed; color: #fff; border-color: #7c3aed; }
        .pagination .cur { background: #7c3aed; color: #fff; border: 1px solid #7c3aed; }
        .pagination .dis { color: #bbb; border: 1px solid #eee; pointer-events: none; }
        .pagination .ell { color: #999; border: none; }

        /* Hidden columns: Publicación (3), Color (8), Notas (13) */
        #tbl th:nth-child(3), #tbl td:nth-child(3),
        #tbl th:nth-child(8), #tbl td:nth-child(8),
        #tbl th:nth-child(13), #tbl td:nth-child(13) { display: none; }
        /* Widen Avalúo column */
        #tbl th:nth-child(9) { min-width: 120px; }
        /* Sortable headers */
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { background: #1a3a6e; }
        th.sortable.sorted { background: #1e4080; }
        .sort-icon { margin-left: 4px; opacity: .45; font-style: normal; }
        .sort-icon.active { opacity: 1; color: #a0c4ff; }

        /* Status columns */
        .th-estado { text-align: center !important; width: 38px; font-size: 1rem !important; letter-spacing: 0 !important; text-transform: none !important; }
        .td-estado { text-align: center; }
        .cb-estado { width: 16px; height: 16px; cursor: pointer; accent-color: #7c3aed; }
        /* Row status colors */
        tbody tr[data-estado="no_dado"] { background: #fee2e2 !important; }
        tbody tr[data-estado="no_dado"]:hover:not(.edit-row) { background: #fecaca !important; }
        tbody tr[data-estado="exitoso"]  { background: #d1fae5 !important; }
        tbody tr[data-estado="exitoso"]:hover:not(.edit-row)  { background: #bbf7d0 !important; }
        /* Modal */
        #modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; }
        #modal-box { background:#fff; border-radius:10px; padding:24px; width:440px; max-width:90vw; box-shadow:0 8px 32px rgba(0,0,0,.25); }
        #modal-box h3 { font-size:1rem; font-weight:700; color:#0f3460; margin-bottom:6px; }
        #modal-box p  { font-size:.82rem; color:#666; margin-bottom:12px; }
        #modal-motivo { width:100%; border:1px solid #d1d5db; border-radius:6px; padding:8px; font-size:.85rem; font-family:inherit; resize:vertical; }
        #modal-motivo.invalid { border-color:#e94560; }
        .modal-footer { display:flex; justify-content:flex-end; gap:8px; margin-top:14px; }
        .btn-modal-cancel { background:#f3f4f6; color:#555; border:none; border-radius:5px; padding:7px 16px; font-size:.85rem; cursor:pointer; }
        .btn-modal-save   { background:#e94560; color:#fff; border:none; border-radius:5px; padding:7px 16px; font-size:.85rem; font-weight:700; cursor:pointer; }

        /* Empty */
        .empty { text-align: center; padding: 48px 20px; color: #999; font-size: .9rem; }
        .empty big { display: block; font-size: 2.5rem; margin-bottom: 10px; }

        @media (max-width: 900px) {
            .filter-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 560px) {
            .filter-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header>
    <div class="brand">
        <span style="font-size:1.8rem">🚗</span>
        <div>
            <h1>Mis RematesCO</h1>
            <p class="subtitle">Registros guardados · Bogotá D.C.</p>
        </div>
    </div>
    <nav style="display:flex;gap:10px;align-items:center;">
        <span class="badge"><?= $total ?> registro<?= $total !== 1 ? 's' : '' ?></span>
        <a href="<?= htmlspecialchars(filterQs(['export'=>1])) ?>" class="nav-link">⬇ Exportar CSV</a>
        <a href="/index.php" class="nav-link">← Volver al listado</a>
    </nav>
</header>

<main>

    <!-- Filters -->
    <div class="filter-card">
        <h2>Filtros</h2>
        <form method="get" action="">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Buscar (radicado, placa, publicación)</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Escribe para buscar…">
                </div>
                <div class="filter-group">
                    <label>Marca</label>
                    <input type="text" name="marca" value="<?= htmlspecialchars($marca) ?>" placeholder="Ej. Chevrolet">
                </div>
                <div class="filter-group">
                    <label>Modalidad</label>
                    <select name="modalidad">
                        <option value="">Todas</option>
                        <option value="Virtual"    <?= $modal === 'Virtual'    ? 'selected' : '' ?>>Virtual</option>
                        <option value="Presencial" <?= $modal === 'Presencial' ? 'selected' : '' ?>>Presencial</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Fecha desde</label>
                    <input type="date" name="fd" value="<?= htmlspecialchars($fd) ?>">
                </div>
                <div class="filter-group">
                    <label>Fecha hasta</label>
                    <input type="date" name="fh" value="<?= htmlspecialchars($fh) ?>">
                </div>
                <div style="display:flex;gap:8px;align-items:flex-end;">
                    <button type="submit" class="btn btn-purple">Filtrar</button>
                    <a href="/remates_vehiculos.php" class="btn btn-ghost">Limpiar</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Stats bar -->
    <div class="stats-bar">
        <div>
            <?php if ($total > 0): ?>
                Mostrando <strong><?= ($page-1)*$perPage+1 ?>–<?= min($page*$perPage, $total) ?></strong>
                de <strong><?= $total ?></strong> registros
                &nbsp;·&nbsp; Página <?= $page ?> de <?= $totalPages ?>
            <?php else: ?>
                Sin resultados
            <?php endif; ?>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrapper">
        <?php if ($rows): ?>
        <table id="tbl">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Radicado</th>
                    <th>Publicación</th>
                    <th>Marca</th>
                    <th>Línea</th>
                    <th>Modelo</th>
                    <th>Placa</th>
                    <th>Color</th>
                    <th class="sortable <?= $sortKey==='avaluo' ? 'sorted' : '' ?>" onclick="location.href='<?= htmlspecialchars(sortUrl('avaluo')) ?>'">Avalúo<?= sortIcon('avaluo') ?></th>
                    <th>Base Remate</th>
                    <th class="sortable <?= $sortKey==='fecha' ? 'sorted' : '' ?>" onclick="location.href='<?= htmlspecialchars(sortUrl('fecha')) ?>'">Fecha / Hora<?= sortIcon('fecha') ?></th>
                    <th>Modalidad</th>
                    <th>Notas</th>
                    <th class="th-estado" title="No se dio el remate">❌</th>
                    <th class="th-estado" title="Remate exitoso">✅</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr id="row-<?= $r['id'] ?>" data-estado="<?= htmlspecialchars($r['estado'] ?? '') ?>">
                    <td><?= ($page-1)*$perPage + $i + 1 ?></td>
                    <td class="td-radicado"><?= htmlspecialchars($r['radicado']) ?></td>
                    <td class="td-pub"><?= htmlspecialchars($r['titulo_pub'] ?? '') ?></td>
                    <td class="td-auto"><?= htmlspecialchars($r['marca'] ?? '') ?></td>
                    <td class="td-auto"><?= htmlspecialchars($r['modelo'] ?? '') ?></td>
                    <td class="td-auto"><?= htmlspecialchars($r['anio'] ?? '') ?></td>
                    <td class="td-placa"><?= htmlspecialchars($r['placa'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['color'] ?? '') ?></td>
                    <td id="td-avaluo-<?= $r['id'] ?>"><?= htmlspecialchars(formatCOP($r['avaluo'] ?? '')) ?></td>
                    <td id="td-base-<?= $r['id'] ?>"><?= htmlspecialchars(formatCOP($r['base_remate'] ?? '')) ?></td>
                    <td class="td-fecha">
                        <?= htmlspecialchars($r['fecha_remate'] ?? '') ?>
                        <?php if ($r['hora_remate']): ?><br><span style="color:#888"><?= htmlspecialchars($r['hora_remate']) ?></span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['modalidad']): ?>
                            <span class="pill <?= $r['modalidad']==='Virtual' ? 'pill-virtual' : 'pill-presencial' ?>">
                                <?= htmlspecialchars($r['modalidad']) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="td-notas"><?= htmlspecialchars($r['notas'] ?? '') ?></td>
                    <td class="td-estado">
                        <input type="checkbox" class="cb-estado" id="cb-noDado-<?= $r['id'] ?>"
                            <?= ($r['estado'] ?? '') === 'no_dado' ? 'checked' : '' ?>
                            onchange="onCheckNoDado(<?= $r['id'] ?>, this.checked)">
                    </td>
                    <td class="td-estado">
                        <input type="checkbox" class="cb-estado" id="cb-exitoso-<?= $r['id'] ?>"
                            <?= ($r['estado'] ?? '') === 'exitoso' ? 'checked' : '' ?>
                            onchange="onCheckExitoso(<?= $r['id'] ?>, this.checked)">
                    </td>
                    <td>
                        <div class="action-btns">
                            <button class="btn-edit" onclick="startEdit(<?= $r['id'] ?>)">✏ Editar</button>
                            <button class="btn-del"  onclick="delRow(<?= $r['id'] ?>)">Eliminar</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="empty">
                <big>🚗</big>
                <?= ($q || $marca || $modal || $fd || $fh)
                    ? 'Sin resultados con los filtros aplicados.'
                    : 'Todavía no hay remates de vehículos registrados.' ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="pagination">
        <?php
        if ($page > 1) {
            echo '<a href="' . htmlspecialchars(filterQs(['pagina'=>1])) . '">«</a>';
            echo '<a href="' . htmlspecialchars(filterQs(['pagina'=>$page-1])) . '">‹</a>';
        } else {
            echo '<span class="dis">«</span><span class="dis">‹</span>';
        }
        $win = 2; $s = max(1,$page-$win); $e = min($totalPages,$page+$win);
        if ($s > 1) { echo '<a href="'.htmlspecialchars(filterQs(['pagina'=>1])).'">1</a>'; if ($s>2) echo '<span class="ell">…</span>'; }
        for ($p = $s; $p <= $e; $p++) {
            if ($p === $page) echo '<span class="cur">'.$p.'</span>';
            else echo '<a href="'.htmlspecialchars(filterQs(['pagina'=>$p])).'">'.$p.'</a>';
        }
        if ($e < $totalPages) { if ($e<$totalPages-1) echo '<span class="ell">…</span>'; echo '<a href="'.htmlspecialchars(filterQs(['pagina'=>$totalPages])).'">'.$totalPages.'</a>'; }
        if ($page < $totalPages) {
            echo '<a href="' . htmlspecialchars(filterQs(['pagina'=>$page+1])) . '">›</a>';
            echo '<a href="' . htmlspecialchars(filterQs(['pagina'=>$totalPages])) . '">»</a>';
        } else {
            echo '<span class="dis">›</span><span class="dis">»</span>';
        }
        ?>
    </nav>
    <?php endif; ?>

</main>

<!-- Modal: motivo no dado -->
<div id="modal-overlay">
    <div id="modal-box">
        <h3>Remate no realizado</h3>
        <p>Indique el motivo por el que el remate no se dio o no se va a dar:</p>
        <textarea id="modal-motivo" rows="4" placeholder="Ej. Suspenso por el juzgado…"></textarea>
        <div class="modal-footer">
            <button class="btn-modal-cancel" onclick="modalCancel()">Cancelar</button>
            <button class="btn-modal-save"   onclick="modalSave()">Guardar</button>
        </div>
    </div>
</div>

<script>
// Cache of row data loaded from the DOM on page render
const rowCache = {};
<?php foreach ($rows as $r): ?>
rowCache[<?= $r['id'] ?>] = <?= json_encode($r) ?>;
<?php endforeach; ?>

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

function esc(s) {
    if (s == null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function startEdit(id) {
    // Close any open edit rows first
    document.querySelectorAll('tr.edit-row').forEach(tr => {
        const origId = tr.dataset.for;
        if (origId) cancelEdit(parseInt(origId));
    });

    const r   = rowCache[id];
    const row = document.getElementById('row-' + id);
    if (!r || !row) return;

    row.style.display = 'none';

    const editRow = document.createElement('tr');
    editRow.className = 'edit-row';
    editRow.id = 'edit-row-' + id;
    editRow.dataset.for = id;
    editRow.innerHTML = `
        <td colspan="16">
            <div style="font-size:.78rem;font-weight:700;color:#7c3aed;margin-bottom:10px">
                ✏ Editando: ${esc(r.radicado)}
            </div>
            ${(r.titulo_pub || r.pub_titulo) ? `
            <div style="display:flex;align-items:flex-start;gap:12px;background:#f0f4ff;border-left:3px solid #7c3aed;border-radius:6px;padding:10px 14px;margin-bottom:12px">
                <div style="flex:1;min-width:0">
                    <div style="font-size:.68rem;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px">Publicación procesal</div>
                    <div style="font-size:.82rem;color:#1a1a2e;line-height:1.4">${esc(r.titulo_pub || r.pub_titulo)}</div>
                </div>
                ${r.pub_enlace ? `<a href="${esc(r.pub_enlace)}" target="_blank" rel="noopener"
                    style="flex-shrink:0;display:inline-flex;align-items:center;gap:5px;background:#7c3aed;color:#fff;text-decoration:none;font-size:.75rem;font-weight:700;padding:5px 11px;border-radius:5px;white-space:nowrap;margin-top:2px">
                    Ver detalle ↗</a>` : ''}
            </div>` : ''}
            <div class="edit-row-form">
                <div class="fg full">
                    <label>Radicado <span style="color:#e94560">*</span></label>
                    <input id="ef-radicado-${id}" value="${esc(r.radicado)}" placeholder="Número de radicado">
                </div>
                <div class="fg">
                    <label>Marca</label>
                    <input id="ef-marca-${id}" value="${esc(r.marca)}">
                </div>
                <div class="fg">
                    <label>Línea</label>
                    <input id="ef-modelo-${id}" value="${esc(r.modelo)}">
                </div>
                <div class="fg">
                    <label>Modelo</label>
                    <input id="ef-anio-${id}" value="${esc(r.anio)}" maxlength="4">
                </div>
                <div class="fg">
                    <label>Placa</label>
                    <input id="ef-placa-${id}" value="${esc(r.placa)}" style="text-transform:uppercase" maxlength="10">
                </div>
                <div class="fg">
                    <label>Color</label>
                    <input id="ef-color-${id}" value="${esc(r.color)}">
                </div>
                <div class="fg">
                    <label>Avalúo</label>
                    <input id="ef-avaluo-${id}" value="${esc(r.avaluo)}" oninput="calcBase(this, 'ef-base-${id}')" placeholder="Ej. 10000000">
                </div>
                <div class="fg">
                    <label>Base de Remate <span style="color:#7c3aed;font-size:.65rem">(auto 70%)</span></label>
                    <input id="ef-base-${id}" value="${esc(r.base_remate)}" placeholder="Auto al guardar avalúo">
                </div>
                <div class="fg">
                    <label>Fecha Audiencia</label>
                    <input type="date" id="ef-fecha-${id}" value="${esc(r.fecha_remate)}">
                </div>
                <div class="fg">
                    <label>Hora</label>
                    <input type="time" id="ef-hora-${id}" value="${esc(r.hora_remate)}">
                </div>
                <div class="fg">
                    <label>Modalidad</label>
                    <select id="ef-modalidad-${id}">
                        <option value="">— Seleccionar —</option>
                        <option value="Virtual"    ${r.modalidad==='Virtual'    ? 'selected':''}>Virtual</option>
                        <option value="Presencial" ${r.modalidad==='Presencial' ? 'selected':''}>Presencial</option>
                    </select>
                </div>
                <div class="fg full">
                    <label>Notas</label>
                    <textarea id="ef-notas-${id}">${esc(r.notas)}</textarea>
                </div>
            </div>
            <div class="edit-save-bar">
                <button class="btn-cancel-inline" onclick="cancelEdit(${id})">Cancelar</button>
                <button class="btn-save-inline"   onclick="saveEdit(${id})">Guardar cambios</button>
            </div>
        </td>`;
    row.parentNode.insertBefore(editRow, row.nextSibling);
    document.getElementById('ef-radicado-' + id).focus();
}

function cancelEdit(id) {
    const editRow = document.getElementById('edit-row-' + id);
    const origRow = document.getElementById('row-'      + id);
    if (editRow) editRow.remove();
    if (origRow) origRow.style.display = '';
}

async function saveEdit(id) {
    const radicadoEl = document.getElementById('ef-radicado-' + id);
    if (!radicadoEl.value.trim()) {
        radicadoEl.classList.add('input-invalid');
        radicadoEl.focus();
        return;
    }
    radicadoEl.classList.remove('input-invalid');

    const fd = new FormData();
    fd.append('action',       'update');
    fd.append('id',            id);
    fd.append('radicado',      radicadoEl.value.trim());
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
        const resp = await fetch('', { method: 'POST', body: fd });
        const d    = await resp.json();
        if (d.error) { alert('Error: ' + d.error); return; }

        // Update cache and re-render visible row
        rowCache[id] = d.remate;
        updateRowDisplay(id, d.remate);
        cancelEdit(id);
    } catch(e) {
        alert('Error de conexión. Intenta de nuevo.');
    }
}

function updateRowDisplay(id, r) {
    const row = document.getElementById('row-' + id);
    if (!row) return;
    const cells = row.querySelectorAll('td');
    // cells: 0=#, 1=radicado, 2=pub, 3=marca, 4=linea, 5=modelo, 6=placa, 7=color, 8=avaluo, 9=base, 10=fecha, 11=modal, 12=notas, 13=actions
    cells[1].textContent = r.radicado;
    cells[3].textContent = r.marca        || '';
    cells[4].textContent = r.modelo       || '';
    cells[5].textContent = r.anio         || '';
    cells[6].textContent = r.placa        || '';
    cells[7].textContent = r.color        || '';
    cells[8].textContent = formatCOP(r.avaluo       || '');
    cells[9].textContent = formatCOP(r.base_remate  || '');
    cells[10].innerHTML  = esc(r.fecha_remate || '') +
        (r.hora_remate ? `<br><span style="color:#888">${esc(r.hora_remate)}</span>` : '');
    if (r.modalidad) {
        const cls = r.modalidad === 'Virtual' ? 'pill-virtual' : 'pill-presencial';
        cells[11].innerHTML = `<span class="pill ${cls}">${esc(r.modalidad)}</span>`;
    } else {
        cells[11].innerHTML = '';
    }
    cells[12].textContent = r.notas || '';
}

// ── Estado: no dado / exitoso ──────────────────────────────────────────────────
let _modalId = null;

function onCheckNoDado(id, checked) {
    if (checked) {
        const cbOk = document.getElementById('cb-exitoso-' + id);
        if (cbOk) cbOk.checked = false;
        _modalId = id;
        const ta = document.getElementById('modal-motivo');
        ta.value = '';
        ta.classList.remove('invalid');
        const ol = document.getElementById('modal-overlay');
        ol.style.display = 'flex';
        ta.focus();
    } else {
        setEstado(id, '');
    }
}

function onCheckExitoso(id, checked) {
    if (checked) {
        const cbX = document.getElementById('cb-noDado-' + id);
        if (cbX) cbX.checked = false;
        setEstado(id, 'exitoso');
    } else {
        setEstado(id, '');
    }
}

function modalCancel() {
    const cb = document.getElementById('cb-noDado-' + _modalId);
    if (cb) cb.checked = false;
    document.getElementById('modal-overlay').style.display = 'none';
    _modalId = null;
}

async function modalSave() {
    const ta = document.getElementById('modal-motivo');
    const motivo = ta.value.trim();
    if (!motivo) { ta.classList.add('invalid'); ta.focus(); return; }
    ta.classList.remove('invalid');
    await setEstado(_modalId, 'no_dado', motivo);
    document.getElementById('modal-overlay').style.display = 'none';
    _modalId = null;
}

async function setEstado(id, estado, motivo = '') {
    const fd = new FormData();
    fd.append('action',  'set_estado');
    fd.append('id',       id);
    fd.append('estado',   estado);
    fd.append('motivo',   motivo);
    try {
        const resp = await fetch('', { method: 'POST', body: fd });
        const d    = await resp.json();
        if (d.error) { alert('Error: ' + d.error); return; }
        const row = document.getElementById('row-' + id);
        if (row) row.dataset.estado = estado;
        if (rowCache[id]) {
            rowCache[id].estado = estado;
            if (d.notas !== null) rowCache[id].notas = d.notas;
        }
    } catch(e) {
        alert('Error de conexión.');
    }
}

async function delRow(id) {
    if (!confirm('¿Eliminar este remate de vehículo? Esta acción no se puede deshacer.')) return;
    const fd = new FormData();
    fd.append('action', 'delete');
    fd.append('id', id);
    try {
        const resp = await fetch('', { method: 'POST', body: fd });
        const d    = await resp.json();
        if (d.error) { alert('Error: ' + d.error); return; }
        const editRow = document.getElementById('edit-row-' + id);
        if (editRow) editRow.remove();
        const row = document.getElementById('row-' + id);
        if (row) row.remove();
    } catch(e) {
        alert('Error de conexión.');
    }
}
</script>
</body>
</html>
