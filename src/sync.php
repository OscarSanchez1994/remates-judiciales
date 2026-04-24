<?php
require_once __DIR__ . '/classes/Scraper.php';

header('Content-Type: application/json');
set_time_limit(600);

const SYNC_CUTOFF = '2025-10-01';

// ── DB connection ─────────────────────────────────────────────────────────────
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

// ── Migrations ────────────────────────────────────────────────────────────────
// departamentos table
db()->exec("
    CREATE TABLE IF NOT EXISTS departamentos (
        depto_id   VARCHAR(5)   NOT NULL PRIMARY KEY,
        nombre     VARCHAR(100) NOT NULL,
        activo     TINYINT(1)   NOT NULL DEFAULT 0,
        created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Seed known departments (only inserts if they don't exist)
db()->exec("
    INSERT IGNORE INTO departamentos (depto_id, nombre, activo) VALUES
        ('11', 'Bogotá D.C.',     1),
        ('25', 'Cundinamarca',    0),
        ('05', 'Antioquia',       0),
        ('76', 'Valle del Cauca', 0),
        ('66', 'Risaralda',       0),
        ('63', 'Quindío',         0),
        ('17', 'Caldas',          0),
        ('73', 'Tolima',          0)
");

// depto_id column on publicaciones
$colCheck = db()->query("SHOW COLUMNS FROM publicaciones LIKE 'depto_id'")->fetchAll();
if (empty($colCheck)) {
    db()->exec("ALTER TABLE publicaciones ADD COLUMN depto_id VARCHAR(5) NOT NULL DEFAULT '11' AFTER article_id");
    try { db()->exec("ALTER TABLE publicaciones ADD INDEX idx_depto (depto_id)"); } catch (Throwable) {}
}

// fecha_pub_date column on publicaciones
$colCheck2 = db()->query("SHOW COLUMNS FROM publicaciones LIKE 'fecha_pub_date'")->fetchAll();
if (empty($colCheck2)) {
    db()->exec("ALTER TABLE publicaciones ADD COLUMN fecha_pub_date DATE NULL");
    try { db()->exec("ALTER TABLE publicaciones ADD INDEX idx_fecha_pub (fecha_pub_date)"); } catch (Throwable) {}
}

// ── AJAX: toggle department active status ─────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'toggle_depto') {
    $deptoId = preg_replace('/\D/', '', $_POST['depto_id'] ?? '');
    if (!$deptoId) { echo json_encode(['error' => 'invalid']); exit; }
    $stmt = db()->prepare('UPDATE departamentos SET activo = 1 - activo WHERE depto_id = ?');
    $stmt->execute([$deptoId]);
    $row = db()->prepare('SELECT activo FROM departamentos WHERE depto_id = ?');
    $row->execute([$deptoId]);
    echo json_encode(['ok' => true, 'activo' => (bool) $row->fetchColumn()]);
    exit;
}

// ── AJAX: get departments list ────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'deptos') {
    $rows = db()->query('SELECT depto_id, nombre, activo FROM departamentos ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function parseDate(string $fecha): ?string
{
    $fecha = trim($fecha);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $fecha, $m))
        return "$m[1]-$m[2]-$m[3]";
    if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-](\d{4})#', $fecha, $m))
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}

function extractArticleId(string $enlace): ?string
{
    if (preg_match('/articleId=(\d+)/i', $enlace, $m)) return $m[1];
    return null;
}

function syncDepto(string $deptoId, bool $fullSync): array
{
    $inserted     = 0;
    $updated      = 0;
    $skipped      = 0;
    $pagesScanned = 0;

    $upsert = db()->prepare('
        INSERT INTO publicaciones (article_id, depto_id, titulo, despacho, fecha_pub_portal, fecha_pub_date, enlace)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            depto_id         = VALUES(depto_id),
            titulo           = VALUES(titulo),
            despacho         = VALUES(despacho),
            fecha_pub_portal = VALUES(fecha_pub_portal),
            fecha_pub_date   = VALUES(fecha_pub_date),
            enlace           = VALUES(enlace)
    ');

    $checkNew = db()->prepare('SELECT id FROM publicaciones WHERE article_id = ?');

    $scraper    = new Scraper($deptoId);
    $page       = 1;
    $totalPages = null;

    do {
        $data = $scraper->fetchPage($page);
        $pagesScanned++;

        if ($totalPages === null) $totalPages = $data['pages'];

        $pageAllOld      = true;
        $pageInsertCount = 0;

        foreach ($data['items'] as $item) {
            $date = parseDate($item['fecha']);

            if ($date !== null && $date < SYNC_CUTOFF) {
                $skipped++;
                continue;
            }

            $pageAllOld = false;
            $aid = extractArticleId($item['enlace']);
            if (!$aid) continue;

            $checkNew->execute([$aid]);
            $existingId = $checkNew->fetchColumn();

            $upsert->execute([$aid, $deptoId, $item['titulo'], $item['despacho'], $item['fecha'], $date, $item['enlace']]);

            if ($existingId) {
                $updated++;
            } else {
                $inserted++;
                $pageInsertCount++;
            }
        }

        if ($pageAllOld) break;
        if (!$fullSync && $pageInsertCount === 0) break;

        $page++;
        if ($page <= $totalPages) usleep(200000);

    } while ($page <= $totalPages);

    return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'pages' => $pagesScanned];
}

// ── Main sync ─────────────────────────────────────────────────────────────────
$fullSync    = isset($_GET['full']);
$singleDepto = trim($_GET['depto'] ?? '');

try {
    if ($singleDepto) {
        // Sync a specific department
        $row = db()->prepare('SELECT depto_id, nombre FROM departamentos WHERE depto_id = ?');
        $row->execute([$singleDepto]);
        $dept = $row->fetch(PDO::FETCH_ASSOC);
        if (!$dept) { echo json_encode(['ok' => false, 'error' => 'Departamento no encontrado']); exit; }

        $result = syncDepto($singleDepto, $fullSync);
        echo json_encode(array_merge(['ok' => true, 'depto' => $dept['nombre']], $result));

    } else {
        // Sync all active departments
        $deptos = db()->query("SELECT depto_id, nombre FROM departamentos WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($deptos)) {
            echo json_encode(['ok' => false, 'error' => 'No hay departamentos activos']);
            exit;
        }

        $totals = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'pages' => 0, 'deptos' => []];
        foreach ($deptos as $dept) {
            $r = syncDepto($dept['depto_id'], $fullSync);
            $totals['inserted'] += $r['inserted'];
            $totals['updated']  += $r['updated'];
            $totals['skipped']  += $r['skipped'];
            $totals['pages']    += $r['pages'];
            $totals['deptos'][] = ['nombre' => $dept['nombre'], 'inserted' => $r['inserted'], 'pages' => $r['pages']];
        }

        echo json_encode(array_merge(['ok' => true], $totals));
    }

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
