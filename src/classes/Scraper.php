<?php

class Scraper
{
    const BASE_URL    = 'https://publicacionesprocesales.ramajudicial.gov.co/web/publicaciones-procesales/inicio';
    const PORTLET_ID  = 'co_com_avanti_efectosProcesales_PublicacionesEfectosProcesalesPortletV2_INSTANCE_BIyXQFHVaYaq';
    const PREFIX      = '_co_com_avanti_efectosProcesales_PublicacionesEfectosProcesalesPortletV2_INSTANCE_BIyXQFHVaYaq_';
    const STRUCTURE_REMATES = '6098997';
    const DEPTO_BOGOTA      = '11';
    const PER_PAGE          = 30;

    private string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Fetch one page of Remates results.
     * Returns ['total' => int, 'pages' => int, 'items' => array]
     */
    public function fetchPage(int $page = 1): array
    {
        $html = $this->request($page);

        $total = $this->parseTotal($html);
        $pages = $total > 0 ? (int) ceil($total / self::PER_PAGE) : 0;

        return [
            'total' => $total,
            'pages' => $pages,
            'items' => $this->parseItems($html),
        ];
    }

    /**
     * Fetch all pages and return all items.
     * Streams results via a callback to allow progressive output.
     * callback(array $items, int $page, int $totalPages): void
     */
    public function fetchAll(callable $callback): void
    {
        $first = $this->fetchPage(1);
        $callback($first['items'], 1, $first['pages']);

        for ($p = 2; $p <= $first['pages']; $p++) {
            $data = $this->fetchPage($p);
            $callback($data['items'], $p, $first['pages']);
            usleep(300000); // 300ms delay to be respectful
        }
    }

    // -------------------------------------------------------------------------

    private function request(int $page): string
    {
        $p = self::PREFIX;
        $params = [
            'p_p_id'        => self::PORTLET_ID,
            'p_p_lifecycle' => '0',
            'p_p_state'     => 'normal',
            'p_p_mode'      => 'view',
            $p . 'action'      => 'filterStructures',
            $p . 'idStructure' => self::STRUCTURE_REMATES,
            $p . 'idDepto'     => self::DEPTO_BOGOTA,
            $p . 'delta'       => self::PER_PAGE,
            $p . 'resetCur'    => 'false',
            $p . 'cur'         => $page,
        ];

        $url = self::BASE_URL . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => 'gzip, deflate',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: es-CO,es;q=0.9,en;q=0.8',
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

    private function parseTotal(string $html): int
    {
        // "Mostrando el intervalo 1 - 10 de 1.855 resultados."
        if (preg_match('/de\s+([\d\.]+)\s+resultados/i', $html, $m)) {
            return (int) str_replace('.', '', $m[1]);
        }
        return 0;
    }

    private function parseItems(string $html): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $items = [];

        $rows = $xpath->query('//tr[contains(@class,"tramites")]');

        foreach ($rows as $row) {
            // Title and detail link
            $titleNode = $xpath->query('.//div[contains(@class,"titulo-publicacion")]//a', $row)->item(0);
            $titulo    = $titleNode ? trim($titleNode->textContent) : '';
            $enlace    = $titleNode ? trim($titleNode->getAttribute('href')) : '';

            // Despacho (from category spans)
            $despacho = '';
            $cats = $xpath->query('.//span[contains(@class,"categoria-ep")]', $row);
            foreach ($cats as $cat) {
                $text = trim($cat->textContent);
                if (str_starts_with($text, 'Despacho:')) {
                    $despacho = trim(substr($text, strlen('Despacho:')));
                    break;
                }
            }

            // Publication date
            $dateNode = $xpath->query('.//p[contains(@class,"publish-date")]', $row)->item(0);
            $fecha    = '';
            if ($dateNode) {
                $fecha = trim(preg_replace('/Fecha\s+de\s+Publicaci[oó]n\s*:/iu', '', $dateNode->textContent));
            }

            if ($titulo !== '') {
                $items[] = [
                    'titulo'   => $titulo,
                    'despacho' => $despacho,
                    'fecha'    => $fecha,
                    'enlace'   => $enlace,
                ];
            }
        }

        return $items;
    }
}
