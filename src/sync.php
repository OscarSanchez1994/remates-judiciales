<?php
require_once __DIR__ . '/classes/Scraper.php';

header('Content-Type: application/json');
set_time_limit(300);

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

// ── Migration: ensure fecha_pub_date column exists ────────────────────────────
$colCheck = db()->query("SHOW COLUMNS FROM publicaciones LIKE 'fecha_pub_date'")->fetchAll();
if (empty($colCheck)) {
    db()->exec("ALTER TABLE publicaciones ADD COLUMN fecha_pub_date DATE NULL");
    try { db()->exec("ALTER TABLE publicaciones ADD INDEX idx_fecha_pub (fecha_pub_date)"); } catch (Throwable) {}
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

// ── Sync ──────────────────────────────────────────────────────────────────────
$inserted     = 0;
$updated      = 0;
$skipped      = 0;
$pagesScanned = 0;

$upsert = db()->prepare('
    INSERT INTO publicaciones (article_id, titulo, despacho, fecha_pub_portal, fecha_pub_date, enlace)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        titulo           = VALUES(titulo),
        despacho         = VALUES(despacho),
        fecha_pub_portal = VALUES(fecha_pub_portal),
        fecha_pub_date   = VALUES(fecha_pub_date),
        enlace           = VALUES(enlace)
');

$checkNew = db()->prepare('SELECT id FROM publicaciones WHERE article_id = ?');

try {
    $scraper    = new Scraper();
    $page       = 1;
    $totalPages = null;
    $fullSync   = isset($_GET['full']); // ?full=1 para sincronización completa

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
                continue; // older than cutoff, skip
            }

            $pageAllOld = false;
            $aid = extractArticleId($item['enlace']);
            if (!$aid) continue;

            $checkNew->execute([$aid]);
            $existingId = $checkNew->fetchColumn();

            $upsert->execute([$aid, $item['titulo'], $item['despacho'], $item['fecha'], $date, $item['enlace']]);

            if ($existingId) {
                $updated++;
            } else {
                $inserted++;
                $pageInsertCount++;
            }
        }

        // Stop if entire page is older than cutoff
        if ($pageAllOld) break;

        // Incremental sync: stop when a page has no new records (already up to date)
        if (!$fullSync && $pageInsertCount === 0) break;

        $page++;
        if ($page <= $totalPages) usleep(200000); // 200ms between pages

    } while ($page <= $totalPages);

    echo json_encode([
        'ok'       => true,
        'inserted' => $inserted,
        'updated'  => $updated,
        'skipped'  => $skipped,
        'pages'    => $pagesScanned,
    ]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
