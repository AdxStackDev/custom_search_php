<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
 * Configuration
 * (You can also pass api_key and cx via the POST body; if omitted,
 * set defaults below.)
 */
const DEFAULT_NUM = 10;
const MAX_TOTAL_RESULTS = 100;

/* ---------- Utility functions (moved from look.php) ---------- */

function safe($x) {
    return htmlspecialchars(is_array($x) ? implode(', ', $x) : (string)$x, ENT_QUOTES);
}

function clamp_int($v, $min, $max, $fb) {
    $n = filter_var($v, FILTER_VALIDATE_INT);
    return ($n === false) ? $fb : max($min, min($max, $n));
}

function valid_date($s) {
    $s = trim($s ?? '');
    return (!$s) ? null : (((($d = DateTime::createFromFormat('Y-m-d', $s)) && $d->format('Y-m-d') === $s) ? $s : null));
}

function list_tokens($s) {
    if (is_array($s)) return $s;
    $parts = preg_split('/[\r\n,]+/', (string)$s);
    $out = [];
    foreach ($parts as $p) { if (($p = trim($p)) !== '') $out[] = $p; }
    return $out;
}

function build_query_text($f, $base) {
    $p = [];
    if ($base !== '') $p[] = $base;
    foreach (list_tokens($f['intext'] ?? '') as $t)  $p[] = 'intext:"' . $t . '"';
    foreach (list_tokens($f['intitle'] ?? '') as $t) $p[] = 'intitle:"' . $t . '"';
    foreach (list_tokens($f['inurl'] ?? '') as $t)   $p[] = 'inurl:"' . $t . '"';
    if (($af = valid_date($f['after'] ?? null)))  $p[] = 'after:' . $af;
    if (($bf = valid_date($f['before'] ?? null))) $p[] = 'before:' . $bf;
    $aroundA = trim($f['aroundA'] ?? '');
    $aroundB = trim($f['aroundB'] ?? '');
    $aroundN = clamp_int($f['aroundN'] ?? '', 1, 10, 3);
    if ($aroundA !== '' && $aroundB !== '') $p[] = '"' . $aroundA . '" AROUND(' . $aroundN . ') "' . $aroundB . '"';
    return trim(implode(' ', $p));
}

function build_cse_params($f, $q, $start, $num) {
    $params = ['q' => $q, 'num' => $num, 'start' => $start];
    if (($ex = trim($f['exact'] ?? '')) !== '')         $params['exactTerms'] = $ex;
    if (($ex = trim($f['exclude'] ?? '')) !== '')       $params['excludeTerms'] = $ex;
    $orTerms = list_tokens($f['orTerms'] ?? '');
    if ($orTerms)                                       $params['orTerms'] = implode(' ', $orTerms);
    if (($hq = trim($f['hq'] ?? '')) !== '')            $params['hq'] = $hq;
    if (($ft = trim($f['filetype'] ?? '')) !== '')      $params['fileType'] = $ft;
    $siteInc = list_tokens($f['site'] ?? ''); $siteExc = list_tokens($f['site_exclude'] ?? '');
    if ($siteInc)      { $params['siteSearch'] = implode(' OR ', $siteInc); $params['siteSearchFilter'] = 'i'; }
    elseif ($siteExc)  { $params['siteSearch'] = implode(' OR ', $siteExc); $params['siteSearchFilter'] = 'e'; }
    $safe = in_array($f['safe'] ?? 'off', ['off', 'medium', 'high']) ? $f['safe'] : 'off'; $params['safe'] = $safe;
    $gl = strtoupper(trim($f['gl'] ?? '')); if ($gl)    $params['gl'] = $gl;
    $lr = trim($f['lr'] ?? ''); if ($lr)                $params['lr'] = $lr;
    $st = ($f['searchType'] ?? '') === 'image' ? 'image' : null; if ($st) $params['searchType'] = 'image';
    $hl = trim($f['hl'] ?? ''); if ($hl)                $params['hl'] = $hl;
    return $params;
}

/* wrapper to call Google Custom Search API */
function http_get_json($p) {
    $url = "https://www.googleapis.com/customsearch/v1?" . http_build_query($p);
    // Use a context that disables peer verify only if you cannot verify SSL; not recommended for prod.
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);
    $res = @file_get_contents($url, false, $context);
    if($res === FALSE) return ['error'=>'Failed to contact API'];
    $data = json_decode($res, true);
    if (!is_array($data)) return ['error' => 'Invalid JSON'];
    return $data;
}

/* helpers to stream files to client */
function send_download_headers(string $filename, string $type): void {
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
}

function export_csv(array $all): void {
    send_download_headers('google_results.csv', 'text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['query','title','link','displayLink','snippet','mime']);
    foreach ($all as $query => $rs) {
        foreach ($rs as $r) {
            fputcsv($out, [
                $query,
                $r['title'] ?? '',
                $r['link'] ?? '',
                $r['displayLink'] ?? '',
                $r['snippet'] ?? '',
                $r['mime'] ?? ''
            ]);
        }
    }
    fclose($out);
    exit;
}

function export_json(array $all): void {
    send_download_headers('google_results.json', 'application/json; charset=utf-8');
    echo json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ----------------- Request handling ----------------- */

// Accept either POST or GET parameters — mirrors original form names
$fields = [
    'api_key','cx','keywords','intitle','inurl','intext','filetype','exact','exclude','orTerms','hq',
    'site','site_exclude','after','before','aroundA','aroundB','aroundN','safe','gl','lr','hl','searchType','num','page'
];
$form = [];
foreach($fields as $f) $form[$f] = $_POST[$f] ?? ($_GET[$f] ?? '');

/* Export endpoint (GET) — uses session stored results if available */
if (isset($_GET['export']) && $_GET['export'] !== '') {
    if (!isset($_SESSION['search_results'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No results to export.'], JSON_PRETTY_PRINT);
        exit;
    }
    if ($_GET['export'] === 'csv') export_csv($_SESSION['search_results']);
    if ($_GET['export'] === 'json') export_json($_SESSION['search_results']);
}

/* If this is a POST (search action), perform the search and return JSON */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $apiKey = trim($form['api_key'] ?? '');
    $cx = trim($form['cx'] ?? '');
    if ($apiKey === '' || $cx === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing api_key or cx (CSE ID).']);
        exit;
    }

    $num = clamp_int($form['num'], 1, 10, DEFAULT_NUM);
    $page = clamp_int($form['page'], 1, 10, 1);
    $start = (($page-1)*$num)+1;

    $keywords = list_tokens($form['keywords']);
    $batch = empty($keywords) ? [''] : $keywords;

    $results = [];
    $totalCount = 0;
    foreach ($batch as $kw) {
        $query = build_query_text($form, $kw);
        $params = build_cse_params($form, $query, $start, $num);
        $params['key'] = $apiKey;
        $params['cx'] = $cx;
        $data = http_get_json($params);
        if (isset($data['error'])) {
            $results[$query] = [['title' => 'Error', 'link' => '', 'snippet' => $data['error'], 'displayLink' => '']];
        } else {
            $items = $data['items'] ?? [];
            $results[$query] = $items;
            $totalCount += count($items);
        }
        // throttle very slightly to be nice to the API
        usleep(120000);
    }

    // persist in session for exports (mirrors original)
    $_SESSION['search_results'] = $results;
    $_SESSION['last_form'] = $_POST;

    echo json_encode(['total' => $totalCount, 'results' => $results], JSON_PRETTY_PRINT);
    exit;
}

/* If reached here with no POST action, return a small info JSON */
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['info' => 'api.php expects POST for search and GET?export=csv|json for exports.']);
exit;
