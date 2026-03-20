<?php

/**
 * Parses a Liferay detail page and extracts structured auction data.
 *
 * The "resumen-publicacion" div contains free-form HTML with tables whose
 * structure varies per court. We support:
 *   - Key-value tables  (2 cols, first col = label)
 *   - Multi-row tables  (first row = headers, remaining rows = data)
 *   - PDF-only pages    (no structured content)
 */
class DetailParser
{
    const BASE_URL  = 'https://publicacionesprocesales.ramajudicial.gov.co/web/publicaciones-procesales/inicio';
    const PORTLET   = 'co_com_avanti_efectosProcesales_PublicacionesEfectosProcesalesPortletV2_INSTANCE_BIyXQFHVaYaq';
    const PREFIX    = '_co_com_avanti_efectosProcesales_PublicacionesEfectosProcesalesPortletV2_INSTANCE_BIyXQFHVaYaq_';

    private string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // ── Column name aliases to canonical field names ──────────────────────────
    private const COL_MAP = [
        'fecha audiencia'             => 'fecha_audiencia',
        'fecha y hora'                => 'fecha_audiencia',
        'fecha y hora para la audiencia de remate' => 'fecha_audiencia',
        'hora'                        => 'hora',
        'no. proceso'                 => 'proceso_numero',
        'proceso'                     => 'proceso_numero',
        'no. proceso - consulta'      => 'proceso_numero',
        'clase'                       => 'clase',
        'bien objeto de subasta'      => 'bien_descripcion',
        'bien'                        => 'bien_descripcion',
        'demandante'                  => 'demandante',
        'demandado'                   => 'demandado',
        'estado de la audiencia'      => 'estado_audiencia',
        'estado'                      => 'estado_audiencia',
        'link para ver el proceso'    => 'proceso_link',
        'enlace'                      => 'proceso_link',
        'link de ingreso a la subasta virtual' => 'acceso_subasta_url',
        'link ingreso subasta'        => 'acceso_subasta_url',
        'acceso subasta'              => 'acceso_subasta_url',
        'modalidad'                   => 'modalidad',
    ];

    /**
     * Fetch and parse a detail page.
     * Returns ['tipo' => string, 'fecha_pub' => string, 'resumen' => string, 'remates' => array]
     */
    public function parse(string $articleId): array
    {
        $html = $this->fetch($articleId);
        return $this->parseHtml($html);
    }

    public function parseHtml(string $html): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);

        // Publication date
        $fechaPub = '';
        $fechaNode = $xpath->query('//div[contains(@class,"datosDescription")]/p')->item(0);
        if ($fechaNode) {
            $fechaPub = trim($fechaNode->textContent);
        }

        // Resumen node (inner HTML of the <p> inside resumen-publicacion)
        $resumenContainer = $xpath->query('//div[contains(@class,"resumen-publicacion")]')->item(0);

        if (!$resumenContainer) {
            $pdfData = $this->extractFromPagePdfs($html);
            return [
                'tipo'      => 'pdf_only',
                'fecha_pub' => $fechaPub,
                'resumen'   => $pdfData['resumen'],
                'remates'   => $pdfData['remates'],
            ];
        }

        // Get the plain text summary (first <p> or text node)
        $resumenText = trim($resumenContainer->textContent);
        $resumenText = preg_replace('/\s+/u', ' ', $resumenText);
        $resumenText = trim(preg_replace('/^Resumen de la publicaci[oó]n/iu', '', $resumenText));

        // Find all tables inside resumen-publicacion
        $tables = $xpath->query('.//table', $resumenContainer);

        if ($tables->length === 0) {
            return [
                'tipo'      => 'texto',
                'fecha_pub' => $fechaPub,
                'resumen'   => $resumenText,
                'remates'   => [],
            ];
        }

        $remates = [];
        $tipo    = 'desconocido';

        foreach ($tables as $table) {
            // Extract context date from text that precedes this table in the resumen
            $contextDate = $this->getContextDate($table);

            $rows = $xpath->query('.//tr', $table);
            if ($rows->length === 0) continue;

            // Read all rows as [text, href?] arrays
            $rawRows = [];
            foreach ($rows as $row) {
                $cells = $xpath->query('./td|./th', $row);
                $rowData = [];
                foreach ($cells as $cell) {
                    $text = trim(preg_replace('/\xc2\xa0|\x{00a0}/u', ' ', $cell->textContent));
                    $text = preg_replace('/\s+/', ' ', $text);
                    // Extract href from any link in the cell
                    $link = '';
                    $anchor = $xpath->query('.//a[@href]', $cell)->item(0);
                    if ($anchor) {
                        $link = trim($anchor->getAttribute('href'));
                    }
                    $rowData[] = ['text' => $text, 'href' => $link];
                }
                if (!empty($rowData)) {
                    $rawRows[] = $rowData;
                }
            }

            if (empty($rawRows)) continue;

            // Detect table type
            $firstRow = $rawRows[0];

            if (count($firstRow) === 2) {
                // Key-value table
                $tipo = 'simple';
                $kvData = [];
                foreach ($rawRows as $row) {
                    if (count($row) < 2) continue;
                    $key   = strtolower(trim($row[0]['text']));
                    $value = trim($row[1]['text']);
                    $href  = $row[1]['href'];
                    $field = self::COL_MAP[$key] ?? null;
                    if ($field) {
                        $kvData[$field] = $value;
                        if ($href && in_array($field, ['proceso_numero', 'acceso_subasta_url'])) {
                            $kvData[$field . '_url'] = $href;
                        }
                    }
                }
                if (!empty($kvData)) {
                    $remate = $this->buildRemate($kvData);
                    // Separate proceso_numero and its link
                    if (isset($kvData['proceso_numero_url'])) {
                        $remate['proceso_link'] = $kvData['proceso_numero_url'];
                    }
                    if (isset($kvData['acceso_subasta_url_url'])) {
                        $remate['acceso_subasta_url'] = $kvData['acceso_subasta_url_url'];
                    }
                    $remates[] = $remate;
                }
            } else {
                // Multi-row table: first row = headers
                $tipo = 'multiple';
                $headers = [];
                foreach ($firstRow as $cell) {
                    $key     = strtolower(trim($cell['text']));
                    $headers[] = self::COL_MAP[$key] ?? null;
                }

                // Data rows (skip header row)
                for ($i = 1; $i < count($rawRows); $i++) {
                    $row = $rawRows[$i];
                    $rowData = [];
                    foreach ($row as $j => $cell) {
                        if (!isset($headers[$j]) || $headers[$j] === null) continue;
                        $field = $headers[$j];
                        $val   = trim($cell['text']);
                        if ($val === '' || $val === "\u{00A0}") continue;
                        $rowData[$field] = $val;
                        if ($cell['href'] && $field === 'proceso_numero') {
                            $rowData['proceso_link'] = $cell['href'];
                        }
                        if ($cell['href'] && $field === 'acceso_subasta_url') {
                            $rowData['acceso_subasta_url'] = $cell['href'];
                        }
                    }
                    if (!empty($rowData)) {
                        // Apply context date when the table has no fecha_audiencia column
                        if (empty($rowData['fecha_audiencia']) && $contextDate !== '') {
                            $rowData['fecha_audiencia'] = $contextDate;
                        }
                        $remates[] = $this->buildRemate($rowData);
                    }
                }
            }
        }

        return [
            'tipo'      => $tipo,
            'fecha_pub' => $fechaPub,
            'resumen'   => $resumenText,
            'remates'   => $remates,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildRemate(array $data): array
    {
        return [
            'fecha_audiencia'   => $data['fecha_audiencia']   ?? '',
            'hora'              => $data['hora']              ?? '',
            'proceso_numero'    => $data['proceso_numero']    ?? '',
            'proceso_link'      => $data['proceso_link']      ?? '',
            'clase'             => $data['clase']             ?? '',
            'bien_descripcion'  => $data['bien_descripcion']  ?? '',
            'demandante'        => $data['demandante']        ?? '',
            'demandado'         => $data['demandado']         ?? '',
            'estado_audiencia'  => $data['estado_audiencia']  ?? '',
            'modalidad'         => $data['modalidad']         ?? '',
            'acceso_subasta_url'=> $data['acceso_subasta_url']?? '',
            'avaluo'            => $data['avaluo']            ?? '',
        ];
    }

    /**
     * Walk backwards through a table's preceding siblings (and parent's siblings
     * as fallback) to find a Spanish-format date like "22 de abril de 2026".
     */
    private function getContextDate(DOMNode $table): string
    {
        $pattern = '/(\d{1,2}\s+de\s+(?:enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)\s+de\s+\d{4})/iu';

        // Walk direct previous siblings of the table
        $node = $table->previousSibling;
        while ($node !== null) {
            $text = trim(preg_replace('/\s+/', ' ', $node->textContent ?? ''));
            if ($text && preg_match($pattern, $text, $m)) {
                return trim($m[1]);
            }
            $node = $node->previousSibling;
        }

        // Fallback: walk parent's previous siblings (for tables nested in <p>/<div>)
        $parent = $table->parentNode;
        if ($parent) {
            $node = $parent->previousSibling;
            while ($node !== null) {
                $text = trim(preg_replace('/\s+/', ' ', $node->textContent ?? ''));
                if ($text && preg_match($pattern, $text, $m)) {
                    return trim($m[1]);
                }
                $node = $node->previousSibling;
            }
        }

        return '';
    }

    // ── PDF extraction ─────────────────────────────────────────────────────────

    /**
     * Find document_library PDF links in the page HTML and extract auction data.
     */
    private function extractFromPagePdfs(string $html): array
    {
        preg_match_all(
            '/href=["\']([^"\']*\/document_library\/get_file[^"\']*)["\']/',
            $html, $m
        );
        $urls = array_unique($m[1]);
        if (empty($urls)) {
            return ['resumen' => '', 'remates' => []];
        }

        $remates = [];
        $resumen = '';

        foreach ($urls as $relUrl) {
            $url = str_starts_with($relUrl, 'http')
                ? $relUrl
                : 'https://publicacionesprocesales.ramajudicial.gov.co' . $relUrl;
            try {
                $data = $this->parsePdfUrl($url);
                if (!empty($data)) {
                    if ($resumen === '' && isset($data['resumen'])) {
                        $resumen = $data['resumen'];
                        unset($data['resumen']);
                    }
                    // Only keep if at least two meaningful fields found
                    $meaningful = array_filter([$data['clase'] ?? '', $data['proceso_numero'] ?? '', $data['bien_descripcion'] ?? '', $data['avaluo'] ?? '']);
                    if (!empty($meaningful) && ($data['fecha_audiencia'] ?? '') !== '') {
                        $remates[] = $this->buildRemate($data);
                    }
                }
            } catch (Exception $e) {
                // skip unreadable PDF
            }
        }

        return ['resumen' => $resumen, 'remates' => $remates];
    }

    private function parsePdfUrl(string $url): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'remate_') . '.pdf';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => 'gzip, deflate',
        ]);
        $pdf   = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno || !$pdf) return [];
        file_put_contents($tmpFile, $pdf);

        try {
            return $this->parsePdfFile($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    private function parsePdfFile(string $filePath): array
    {
        // Get page count to focus on last pages for large documents
        $pageCount = 1;
        $info = shell_exec('pdfinfo ' . escapeshellarg($filePath) . ' 2>/dev/null');
        if ($info && preg_match('/Pages:\s+(\d+)/i', $info, $m)) {
            $pageCount = (int) $m[1];
        }

        // For documents > 8 pages, extract only the last 8 pages (relevant info is at the end)
        $startPage = max(1, $pageCount - 7);
        $text = shell_exec(
            'pdftotext -f ' . $startPage . ' -l ' . $pageCount .
            ' ' . escapeshellarg($filePath) . ' - 2>/dev/null'
        );

        if (empty(trim($text ?? ''))) return [];

        $data = $this->parsePdfText($text);
        // Capture a short summary from the extracted text
        $data['resumen'] = trim(preg_replace('/\s+/', ' ', substr($text, 0, 600)));
        return $data;
    }

    private function parsePdfText(string $text): array
    {
        // Join URL line-continuations: if a URL spans multiple lines, merge them
        $normalized = preg_replace(
            '/([A-Za-z0-9%\-_\.~:\/\?#\[\]@!$&\'()*+,;=])\n([A-Za-z0-9%\-_\.~:\/\?#\[\]@!$&\'()*+,;=])/',
            '$1$2',
            $text
        );

        $data = [];

        // Tipo de bien
        if (preg_match(
            '/REMATE\s+(?:VIRTUAL\s+)?del\s+(inmueble|veh[íi]culo|bien|local|apartamento|casa|lote|bodega|establecimiento)/iu',
            $normalized, $m
        )) {
            $data['clase'] = strtoupper($m[1]);
        }

        // Proceso number
        if (preg_match('/PROCESO:\s*No\.?\s*([\d\-]+)/i', $normalized, $m)) {
            $data['proceso_numero'] = trim($m[1]);
        } elseif (preg_match('/radicad[oa]\s*(?:No\.?)?\s*([\d\-]+)/i', $normalized, $m)) {
            $data['proceso_numero'] = trim($m[1]);
        }

        // Date & time — prefer auction-contextual matches
        // Pattern 1: "hora de las 2:30 pm del 13 de ABRIL de 2026"
        if (preg_match(
            '/hora\s+de\s+las\s+(\d{1,2}:\d{2}\s*(?:am|pm)?)\s+del\s+(\d{1,2}\s+de\s+\w+\s+de\s+\d{4})/iu',
            $normalized, $m
        )) {
            $data['hora']            = trim($m[1]);
            $data['fecha_audiencia'] = trim($m[2]);
        // Pattern 2: "hora de inicio las dos y treinta de la tarde (2:30 pm)" + contextual date
        } elseif (preg_match(
            '/(?:programad[ao]\s+para\s+el\s+d[íi]a\s*|fijad[ao]\s+para\s+el\s*)(\d{1,2}\s+de\s+\w+\s+de\s+\d{4})/iu',
            $normalized, $m
        )) {
            $data['fecha_audiencia'] = trim($m[1]);
            if (preg_match('/hora\s+de\s+inicio\s+las?\s+([\d]{1,2}:\d{2}\s*(?:am|pm)?)/iu', $normalized, $hm)) {
                $data['hora'] = trim($hm[1]);
            }
        }

        // Asset identifier: folio de matrícula or vehicle plate
        if (preg_match('/folio\s+de\s+matr[íi]cula\s+inmobiliaria\s+([\w\-]+)/iu', $normalized, $m)) {
            $data['bien_descripcion'] = 'Matrícula: ' . trim($m[1]);
        } elseif (preg_match('/placa\s*[:\s]+([A-Z]{3}[\-\s]?\d{3}[A-Z]?)/i', $normalized, $m)) {
            $data['bien_descripcion'] = 'Placa: ' . strtoupper(trim($m[1]));
        }

        // Avalúo / precio base
        if (preg_match(
            '/avalua(?:do\s+en|[oó]\s+(?:comercial\s+)?(?:de\s+)?)\$?\s*([\d\.\,]+(?:\.\d{3})*(?:,\d+)?)/iu',
            $normalized, $m
        )) {
            $data['avaluo'] = trim($m[1]);
        } elseif (preg_match(
            '/precio\s+base\s+(?:de\s+remate\s+)?(?:es\s+)?(?:de\s+)?\$?\s*([\d\.\,]+)/iu',
            $normalized, $m
        )) {
            $data['avaluo'] = trim($m[1]);
        }

        // Virtual meeting link (Teams / Zoom / Meet) — use normalized to handle line-broken URLs
        if (preg_match('/(https:\/\/teams\.microsoft\.com\/[^\s"\'<>\)]+)/i', $normalized, $m)) {
            $data['acceso_subasta_url'] = rtrim(trim($m[1]), '.');
        } elseif (preg_match('/(https:\/\/zoom\.us\/[^\s"\'<>\)]+)/i', $normalized, $m)) {
            $data['acceso_subasta_url'] = rtrim(trim($m[1]), '.');
        } elseif (preg_match('/(https:\/\/meet\.google\.com\/[^\s"\'<>\)]+)/i', $normalized, $m)) {
            $data['acceso_subasta_url'] = rtrim(trim($m[1]), '.');
        }

        return $data;
    }

    private function fetch(string $articleId): string
    {
        $p = self::PREFIX;
        $params = [
            'p_p_id'        => self::PORTLET,
            'p_p_lifecycle' => '0',
            'p_p_state'     => 'normal',
            'p_p_mode'      => 'view',
            $p . 'jspPage'   => '/META-INF/resources/detail.jsp',
            $p . 'articleId' => $articleId,
        ];

        $url = self::BASE_URL . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
                'Accept-Language: es-CO,es;q=0.9',
            ],
        ]);

        $html  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException("cURL error ($errno): $error");
        }

        return $html ?: '';
    }
}
