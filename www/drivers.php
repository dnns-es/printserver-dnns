<?php
/**
 * Sistema de distribución de drivers de impresoras.
 * - Descarga drivers oficiales y los cachea localmente
 * - Ofrece fallback a enlace del fabricante si falla
 * - Los PCs clientes bajan desde el server
 */

if (!defined('DRIVERS_DIR')) {
    define('DRIVERS_DIR', __DIR__ . '/drivers');
}
if (!is_dir(DRIVERS_DIR)) @mkdir(DRIVERS_DIR, 0755, true);

/** Detectar fabricante a partir del modelo */
function detectManufacturer($modelAndMake) {
    $s = strtolower($modelAndMake);
    $brands = [
        'brother'   => 'Brother',
        'hp'        => 'HP',
        'hewlett'   => 'HP',
        'epson'     => 'Epson',
        'canon'     => 'Canon',
        'samsung'   => 'Samsung',
        'xerox'     => 'Xerox',
        'lexmark'   => 'Lexmark',
        'ricoh'     => 'Ricoh',
        'kyocera'   => 'Kyocera',
        'konica'    => 'Konica Minolta',
        'oki'       => 'OKI',
    ];
    foreach ($brands as $key => $name) {
        if (strpos($s, $key) !== false) return $name;
    }
    return null;
}

/** Extraer modelo del string "Marca Modelo Series" */
function extractModel($fullName, $manufacturer) {
    $clean = trim(preg_replace('/\b(series|serie|printer|impresora)\b/i', '', $fullName));
    $clean = preg_replace('/^' . preg_quote($manufacturer, '/') . '\s*/i', '', $clean);
    return trim($clean);
}

/** URLs de descarga oficial por fabricante.
 *  Primero probamos URLs directas conocidas por modelo exacto.
 *  Si no, fallback a Google site-scoped search.
 */
function getSearchURL($manufacturer, $model) {
    // URLs directas conocidas por modelo exacto
    $directURLs = [
        'Brother DCP-L3560CDW' => 'https://support.brother.com/g/b/downloadtop.aspx?c=es&lang=es&prod=dcpl3560cdw_us_eu_as',
        'Epson WF-2840' => 'https://www.epson.es/es_ES/support/sc/epson-workforce-wf-2840dwf/s/s1620',
        'Epson WF-2840 Series' => 'https://www.epson.es/es_ES/support/sc/epson-workforce-wf-2840dwf/s/s1620',
    ];
    $key = "$manufacturer $model";
    if (isset($directURLs[$key])) return $directURLs[$key];

    // Patrones por fabricante (cuando se pueda construir la URL directa)
    if ($manufacturer === 'Brother') {
        $code = strtolower(str_replace('-', '', $model));
        return "https://support.brother.com/g/b/downloadtop.aspx?c=es&lang=es&prod={$code}_us_eu_as";
    }

    // Fallback: Google site-scoped search
    $sites = [
        'Brother' => 'support.brother.com',
        'HP'      => 'support.hp.com',
        'Epson'   => 'epson.es/support',
        'Canon'   => 'canon.es/support',
        'Samsung' => 'samsung.com/es/support',
        'Xerox'   => 'support.xerox.com',
        'Lexmark' => 'lexmark.com/es_es/support',
        'Ricoh'   => 'support.ricoh.com',
        'Kyocera' => 'kyoceradocumentsolutions.es',
        'Konica Minolta' => 'konicaminolta.es/business/support',
        'OKI'     => 'oki.com/es/printing/support',
    ];
    $site = $sites[$manufacturer] ?? null;
    $query = urlencode(($site ? "site:$site " : "") . "$manufacturer $model driver");
    return "https://www.google.com/search?q={$query}";
}

/** Candidatos URL directos conocidos por fabricante y modelo (cuando se sabe el patr\u00f3n) */
function getDriverCandidates($manufacturer, $model, $so) {
    $candidates = [];
    $modelLower = strtolower(str_replace([' ', '-', '_'], '', $model));

    if ($manufacturer === 'Brother') {
        // Brother usa IDs de producto internos, no podemos construirlos sin conocer el c\u00f3digo
        // Ofrecemos solo la b\u00fasqueda general
    }

    if ($manufacturer === 'HP') {
        // HP tambi\u00e9n usa IDs opacos
    }

    if ($manufacturer === 'Epson') {
        // Epson tiene URLs m\u00e1s predecibles para CUPS filter (Linux)
        if ($so === 'linux') {
            $candidates[] = [
                'name' => 'Epson Printer Driver for Linux (escpr)',
                'url' => 'https://download3.ebz.epson.net/dsc/f/03/00/13/34/86/0f82e6e12ddcb3e5d4d4fff7b9fb252ef15f4a67/epson-inkjet-printer-escpr-1.7.18-1lsb3.2.src.rpm',
                'ext' => 'rpm'
            ];
        }
    }

    return $candidates;
}

/** Descargar archivo via curl con timeout razonable */
function downloadFile($url, $destPath, $timeoutSec = 60) {
    $ch = curl_init($url);
    $fp = fopen($destPath, 'w');
    if (!$fp) return ['ok' => false, 'error' => 'No se puede escribir en ' . $destPath];
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_USERAGENT => 'Mozilla/5.0 PrintServerDNNS/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $ok = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $httpCode !== 200) {
        @unlink($destPath);
        return ['ok' => false, 'error' => "HTTP $httpCode $err"];
    }
    return ['ok' => true, 'size' => $size];
}

/** Intenta descargar drivers para una impresora */
function fetchDriversForPrinter($printerId) {
    $printer = db()->prepare("SELECT * FROM impresoras WHERE id = ?");
    $printer->execute([$printerId]);
    $p = $printer->fetch(PDO::FETCH_ASSOC);
    if (!$p) return ['ok' => false, 'error' => 'Impresora no encontrada'];

    $manufacturer = $p['fabricante'] ?: detectManufacturer($p['nombre']);
    $model = $p['modelo'] ?: extractModel($p['nombre'], $manufacturer ?? '');

    if (!$manufacturer) return ['ok' => false, 'error' => 'No se pudo detectar fabricante'];

    $found = [];
    $downloaded = 0;
    $dir = DRIVERS_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $manufacturer . '_' . $model);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    foreach (['windows', 'mac', 'linux'] as $so) {
        $cands = getDriverCandidates($manufacturer, $model, $so);
        foreach ($cands as $c) {
            $fname = basename(parse_url($c['url'], PHP_URL_PATH));
            if (!$fname) $fname = "driver_{$so}." . ($c['ext'] ?? 'bin');
            $targetPath = $dir . '/' . $fname;

            // Ya descargado?
            $exists = db()->prepare("SELECT id FROM drivers WHERE impresora_id = ? AND archivo = ?");
            $exists->execute([$printerId, $targetPath]);
            if ($exists->fetch()) continue;

            $res = downloadFile($c['url'], $targetPath);
            if ($res['ok']) {
                $ins = db()->prepare("INSERT INTO drivers (impresora_id, sistema_operativo, nombre, archivo, tamano, url_origen, fabricante) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$printerId, $so, $c['name'], $targetPath, $res['size'], $c['url'], $manufacturer]);
                $downloaded++;
                $found[] = $c['name'];
            }
        }
    }

    return ['ok' => true, 'manufacturer' => $manufacturer, 'model' => $model,
            'downloaded' => $downloaded, 'found' => $found,
            'search_url' => getSearchURL($manufacturer, $model)];
}

/** Listar drivers disponibles por impresora */
function listDriversForPrinter($printerId) {
    $stmt = db()->prepare("SELECT * FROM drivers WHERE impresora_id = ? ORDER BY sistema_operativo, created_at DESC");
    $stmt->execute([$printerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
