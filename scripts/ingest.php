<?php
// Autoload dependencies from the nearest vendor directory
$autoloadDir = __DIR__;
while ($autoloadDir !== dirname($autoloadDir)) {
    if (file_exists($autoloadDir . '/vendor/autoload.php')) {
        require_once $autoloadDir . '/vendor/autoload.php';
        break;
    }
    $autoloadDir = dirname($autoloadDir);
}
ob_start(); // Начинаем буферизацию вывода
@ini_set('display_errors','0');
@ini_set('html_errors','0');

require_once __DIR__ . '/../inc/auth.php'; // Предполагается, что db() здесь

use OpenAI;
use Dotenv\Dotenv;
use voku\helper\HtmlDomParser;
if (!function_exists('t')) { function t($k){ return $k; } }

/* ---------- ENV & LOG PRELUDE (moved up) ---------- */
// Ensure data directory exists and use it for logs by default
$dataDir = __DIR__ . '/../data';
if (!file_exists($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$progressFile = $dataDir . '/progress.json';
$outputFile   = $dataDir . '/embeddings.json';
$indexFile    = $dataDir . '/index.json';

// Log file inside data/ where we created the dir above
$logFile = $dataDir . '/ingest.log';

// Dependency availability flag — do not fatal() if composer deps missing
$deps_ok = true;
if (!class_exists('\Dotenv\Dotenv') || !class_exists('\voku\helper\HtmlDomParser')) {
    $deps_ok = false;
    // Write a note to PHP error log so admins can see it even if file writes fail
    error_log('[ingest] Missing optional dependencies: dotenv or HtmlDomParser. Some features will be degraded.');
}

function logMsg($msg)
{
    global $logFile, $dataDir;
    $ts = '[' . date('c') . '] ' . $msg . PHP_EOL;
    if (!empty($logFile)) {
        $ok = @file_put_contents($logFile, $ts, FILE_APPEND);
        if ($ok === false) {
            // fallback to central project error log
            @file_put_contents(__DIR__ . '/../error.log', $ts, FILE_APPEND);
            // and to PHP system logger
            error_log($msg);
        }
    } else {
        @file_put_contents(__DIR__ . '/../error.log', $ts, FILE_APPEND);
        error_log($msg);
    }
}

if ($deps_ok && file_exists(__DIR__ . '/../.env')) {
    try {
        Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();
    } catch (Throwable $e) {
        logMsg('Dotenv load failed: ' . $e->getMessage());
    }
} else if (!$deps_ok) {
    // If dependencies are missing and this script is embedded into admin UI,
    // we will continue but show a soft warning in the UI where appropriate.
    logMsg('Optional dependencies not available — running in degraded mode.');
}

logMsg('=== ingest.php loaded, PHP ' . PHP_VERSION . ' ===');
// Log SAPI and arguments for debugging
$sapi = php_sapi_name();
$args = isset($argv) ? json_encode($argv) : '[]';
logMsg("SAPI: $sapi, ARGV: $args");

// keep JSON clean: log errors instead of outputting
set_error_handler(function ($severity, $message, $file, $line) {
    logMsg("PHP ERROR [$severity] $message in $file:$line");
    return true; // prevent default output to keep JSON clean
});
/* ---------- AJAX API (preview/start) ---------- */
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    $pdo = db();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'preview') {
        $input = array_filter(array_map('trim', explode("\n", isset($_POST['urls']) ? $_POST['urls'] : '')));
        $all   = [];
        foreach ($input as $u) {
            if ($u === '') continue;
            if (preg_match('/sitemap.*\.xml$/i', $u)) {
                try { $sx = @simplexml_load_file($u); if ($sx) { foreach ($sx->url as $n) { $all[] = (string)$n->loc; } } } catch (Exception $e) {}
            } else { $all[] = $u; }
        }
        $all = array_values(array_unique($all));
        $st  = $pdo->prepare("SELECT url,status,last_modified,last_trained_at FROM pages WHERE url=?");
        $statuses = [];
        foreach ($all as $url) {
            $st->execute([$url]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $statuses[$url] = 'new';
            } elseif ($row['status'] === 'ready' && $row['last_modified'] <= $row['last_trained_at']) {
                $statuses[$url] = 'ready';
            } else {
                $statuses[$url] = 'update';
            }
        }
        $counts = ['new'=>0,'update'=>0,'ready'=>0];
        $list = [];
        foreach ($statuses as $u=>$s){ $counts[$s]++; $list[]=['url'=>$u,'status'=>$s]; }
        jsonResp(['ok'=>true,'total'=>count($all),'counts'=>$counts,'list'=>$list]);
    }

    if ($action === 'start') {
        $pages = array_filter(array_map('trim', explode("\n", isset($_POST['urls']) ? $_POST['urls'] : '')));
        $excl  = array_filter(array_map('trim', explode("\n", isset($_POST['exclusions']) ? $_POST['exclusions'] : '')));
        $mode  = isset($_POST['mode']) ? $_POST['mode'] : 'smart'; // smart | new_only | reprocess_all

        // 1) Expand sitemaps
        $expanded = [];
        foreach ($pages as $u) {
            if (preg_match('/sitemap.*\.xml$/i', $u)) {
                try { $sx = @simplexml_load_file($u); if ($sx) { foreach ($sx->url as $n) { $expanded[] = (string)$n->loc; } } } catch (Exception $e) {}
            } else { $expanded[] = $u; }
        }

        // 2) Apply exclusions
        $filtered = [];
        foreach ($expanded as $u) {
            $skip = false;
            foreach ($excl as $ex) {
                if ($ex === '') continue;
                if ($ex[0] === '*' && substr($ex,-1) === '*') {
                    $p = '/' . str_replace('\\*','.*', preg_quote($ex,'/')) . '/';
                    if (preg_match($p, $u)) { $skip = true; break; }
                } elseif (strpos($u, $ex) !== false) { $skip = true; break; }
            }
            if (!$skip) $filtered[] = $u;
        }
        if (!$filtered) jsonResp(['ok'=>false,'error'=>'No pages after filtering']);

        // 3) Classify by status from DB (new / update / ready)
        $pdo = db();
        $st  = $pdo->prepare("SELECT status,last_modified,last_trained_at FROM pages WHERE url=?");
        $classified = [];// [[url,status]]
        $counts = ['new'=>0,'update'=>0,'ready'=>0];
        foreach ($filtered as $url) {
            $st->execute([$url]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) { $s = 'new'; }
            else if ((isset($row['status']) ? $row['status'] : '') === 'ready' && (isset($row['last_modified']) ? $row['last_modified'] : null) <= (isset($row['last_trained_at']) ? $row['last_trained_at'] : null)) { $s = 'ready'; }
            else { $s = 'update'; }
            $classified[] = ['url'=>$url,'status'=>$s];
            $counts[$s]++;
        }

        // 4) Decide which to train according to mode
        $toTrain = [];
        foreach ($classified as $it) {
            if ($mode === 'new_only' && $it['status'] !== 'new') continue;
            if ($mode === 'smart' && $it['status'] === 'ready') continue; // only new + update
            $toTrain[] = $it['url'];
        }
        if (!$toTrain) jsonResp(['ok'=>false,'error'=>'Nothing to process','counts'=>$counts]);

        // 5) Persist queue
        $pdo->beginTransaction();
        $host = parse_url($toTrain[0], PHP_URL_HOST);
        $sid = $pdo->prepare("SELECT id FROM sites WHERE url=?");
        $sid->execute([$host]);
        $siteId = $sid->fetchColumn();
        if (!$siteId) { $pdo->prepare("INSERT INTO sites (url) VALUES (?)")->execute([$host]); $siteId = $pdo->lastInsertId(); }

        $insPage = $pdo->prepare("INSERT INTO pages (site_id,url,status) VALUES (?,?, 'pending') ON DUPLICATE KEY UPDATE status='pending'");
        foreach ($toTrain as $u) { $insPage->execute([$siteId, $u]); }

        $pdo->prepare("INSERT INTO trainings (site_id,total_pages,status) VALUES (?,?, 'running')")->execute([$siteId, count($toTrain)]);
        $tid = (int)$pdo->lastInsertId();
        $pdo->commit();

        // 6) Launch worker (multi-strategy)
        $spawn = triggerBackground($tid);
        jsonResp(['ok'=>true,'tid'=>$tid,'counts'=>$counts,'selected'=>count($toTrain),'mode'=>$mode,'spawn'=>$spawn]);
    }

    if ($action === 'stats') {
        $rows = $pdo->query("SELECT s.url,
                                    COUNT(t.id) AS trainings,
                                    COALESCE(SUM(t.total_pages),0) AS total_pages,
                                    COALESCE(SUM(t.processed_pages),0) AS processed,
                                    MAX(t.finished_at) AS last_trained
                               FROM sites s
                               LEFT JOIN trainings t ON t.site_id = s.id
                              GROUP BY s.id, s.url
                              ORDER BY (last_trained IS NULL), last_trained DESC, s.url ASC")
                     ->fetchAll(PDO::FETCH_ASSOC);
        jsonResp(['ok'=>true,'rows'=>$rows]);
    }

    if ($action === 'summary') {
        $sites = (int)$pdo->query("SELECT COUNT(*) FROM sites")->fetchColumn();
        $trained = (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE status='ready'")->fetchColumn();
        $running = (int)$pdo->query("SELECT COUNT(*) FROM trainings WHERE status='running'")->fetchColumn();
        jsonResp(['ok'=>true,'sites'=>$sites,'trained'=>$trained,'running'=>$running]);
    }

    jsonResp(['ok'=>false,'error'=>'Unknown action']);
}


/**
 * Try to spawn background training in a way that works on most hosts.
 * Prefer HTTP self-call to guarantee same PHP version as web SAPI,
 * then fallback to proc_open/shell_exec/popen.
 * Returns a short string with the chosen method.
 */
function triggerBackground($tid)
{
    // Prefer HTTP self-call to guarantee the same PHP version as web SAPI
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $port   = $https ? 443 : 80;
    $base   = rtrim(dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/scripts'), '/');
    $path   = $base . '/ingest.php?run=1&tid=' . (int)$tid;

    // Fire-and-forget via async socket
    $endpoint = ($https ? 'ssl://' : '') . $host . ':' . $port;
    $ok = false;
    try {
        $fp = @stream_socket_client($endpoint, $errno, $errstr, 1, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if ($fp) {
            $req = "GET $path HTTP/1.1\r\nHost: $host\r\nUser-Agent: BRS-Async\r\nConnection: Close\r\n\r\n";
            @fwrite($fp, $req);
            @fclose($fp);
            $ok = true;
        }
    } catch (Exception $e) { $ok = false; }
    logMsg("spawn(http-self): $scheme://$host$path => " . ($ok ? 'ok' : 'fail'));
    if ($ok) return 'http';

    // Fallbacks (may invoke a different PHP binary on some hosts)
    $script = __FILE__;
    $log    = __DIR__ . '/../ingest.log';
    $cmd    = PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . (int)$tid;

    // 1) proc_open
    if (function_exists('proc_open')) {
        $descriptors = [0 => ['pipe','r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
        $process = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell'=>true]);
        if (is_resource($process)) {
            foreach ($pipes as $p) { if (is_resource($p)) @fclose($p); }
            @proc_close($process);
            logMsg("spawn(proc_open): $cmd");
            return 'proc_open';
        }
    }

    // 2) shell_exec
    $sh = $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
    if (function_exists('shell_exec')) {
        @shell_exec($sh);
        logMsg("spawn(shell_exec): $sh");
        return 'shell_exec';
    }

    // 3) popen
    if (function_exists('popen')) {
        $h = @popen($sh, 'r'); if (is_resource($h)) @pclose($h);
        logMsg("spawn(popen): $sh");
        return 'popen';
    }

    return 'none';
}

/* ---------- HTTP BACKGROUND RUNNER ---------- */
if (isset($_GET['run']) && $_GET['run'] === '1' && isset($_GET['tid']) && is_numeric($_GET['tid'])) {
    $tid = (int)$_GET['tid'];
    // Detach from client: send quick OK and continue in background
    @ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    echo "OK\n";
    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
    if (function_exists('session_write_close')) { @session_write_close(); }
    ignore_user_abort(true);
    @set_time_limit(0);
    logMsg("HTTP run trigger received for training #$tid");
    runTraining($tid);
    exit;
}

/* ---------- CRON HANDLER ---------- */
if (isset($_GET['cron']) && $_GET['cron'] === '1') {
    $pdo = db();
    $ids = $pdo->query("SELECT id FROM trainings WHERE status='running'")->fetchAll(PDO::FETCH_COLUMN);
    logMsg("Cron HTTP trigger: found " . count($ids) . " running trainings");
    foreach ($ids as $tid) {
        logMsg("Cron: running training #$tid");
        runTraining($tid);
    }
    echo "Cron executed: " . count($ids) . " trainings processed.";
    exit;
}


/* ---------- PRICING ---------- */
function getEmbeddingConfig($pdo)
{
    $defaultModel = 'text-embedding-ada-002';
    $priceMap = [
        'text-embedding-3-small' => 0.02, // $ / 1M tokens
        'text-embedding-3-large' => 0.13,
        'text-embedding-ada-002' => 0.10,
    ];

    $stm = $pdo->prepare("SELECT value FROM api_keys WHERE name='embedding_model'");
    $stm->execute();
    $model = $stm->fetchColumn() ?: $defaultModel;

    return [
        'model'      => $model,
        'pricePerM'  => isset($priceMap[$model]) ? $priceMap[$model] : $priceMap[$defaultModel],
        'chunk_size' => 500,
    ];
}

function getOpenAIKey($pdo)
{
    $stm = $pdo->prepare("SELECT value FROM api_keys WHERE name='openai_key'");
    $stm->execute();
    return $stm->fetchColumn() ?: '';
}

/**
 * Ensure valid UTF-8: convert encodings when possible, drop invalid bytes, normalize and strip control chars.
 */
function utf8_clean($s)
{
    if ($s === null || $s === '') return '';
    // unify newlines and replace NBSP with space
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/\x{00A0}/u', ' ', $s);

    // detect and convert to UTF-8 when needed
    if (!mb_check_encoding($s, 'UTF-8')) {
        $enc = @mb_detect_encoding($s, ['UTF-8','Windows-1251','CP1251','KOI8-R','ISO-8859-1','CP1252','ASCII'], true);
        if ($enc && $enc !== 'UTF-8') {
            $s = @mb_convert_encoding($s, 'UTF-8', $enc);
        }
    }
    // drop any invalid sequences that remain
    $s = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    // normalize if intl is available
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_C);
    }
    // remove control characters except tab/newline
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $s);
    return trim($s);
}

/**
 * Chunk UTF-8 string by byte size without breaking multibyte characters.
 * @return array<int,string>
 */
function utf8_chunks($s, $maxBytes)
{
    $out = [];
    $len = strlen($s); // bytes
    $off = 0;
    while ($off < $len) {
        $piece = mb_strcut($s, $off, $maxBytes, 'UTF-8');
        if ($piece === '') break;
        $out[] = $piece;
        $off += strlen($piece);
    }
    return $out;
}

/**
 * Strip boilerplate and return main text content.
 *  - removes scripts, styles, nav, header, footer, aside, svg, iframe
 *  - collapses whitespace
 *  - concatenates FAQ json‑ld into "Q: … A: …" blocks
 */
function extractText($html)
{
    // Keep a raw copy for structured data parsing (scripts, attributes, hidden blocks)
    $raw = utf8_clean($html);
    $blocks = [];

    // 0) Canonical and OpenGraph URL extraction (helps link fidelity)
    $canon = null;
    if (preg_match('~<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']~i', $raw, $mCanon)) {
        $canon = trim($mCanon[1]);
    }
    $ogurl = null;
    if (preg_match('~<meta[^>]+property=["\']og:url["\'][^>]*content=["\']([^"\']+)["\']~i', $raw, $mOg)) {
        $ogurl = trim($mOg[1]);
    }
    $canonLines = [];
    if (!empty($canon))  { $canonLines[] = 'CANONICAL: ' . $canon; }
    if (!empty($ogurl))  { $canonLines[] = 'OG_URL: ' . $ogurl; }
    if ($canonLines) { $blocks[] = implode("\n", $canonLines); }

    // 1) Parse JSON‑LD (FAQ + Product/Offers)
    if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $raw, $m)) {
        foreach ($m[1] as $json) {
            $json = trim($json);
            $data = json_decode($json, true);
            if (!$data) { continue; }

            $graph = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
            foreach ($graph as $node) {
                if (!is_array($node) || !isset($node['@type'])) continue;
                $type = is_array($node['@type']) ? (isset($node['@type'][0]) ? $node['@type'][0] : '') : $node['@type'];

                // FAQ
                if ($type === 'FAQPage' && isset($node['mainEntity'])) {
                    $faq = [];
                    foreach ($node['mainEntity'] as $qa) {
                        if (!isset($qa['name'], $qa['acceptedAnswer']['text'])) continue;
                        $faq[] = "Q: " . $qa['name'] . "\nA: " . strip_tags($qa['acceptedAnswer']['text']);
                    }
                    if ($faq) {
                        $blocks[] = "FAQ\n" . implode("\n\n", $faq);
                    }
                }

                // Product + Offers
                if (strcasecmp($type, 'Product') === 0) {
                    $prod = [];
                    $prod[] = 'PRODUCT: ' . trim((string)(isset($node['name']) ? $node['name'] : ''));
                    if (!empty($node['sku']))      $prod[] = 'SKU: ' . $node['sku'];
                    if (!empty($node['brand']))    {
                        if (is_array($node['brand'])) {
                            $brand = isset($node['brand']['name']) ? $node['brand']['name'] : json_encode($node['brand'], JSON_UNESCAPED_UNICODE);
                        } else {
                            $brand = $node['brand'];
                        }
                        $prod[] = 'Brand: ' . $brand;
                    }
                    if (!empty($node['description'])) $prod[] = 'Description: ' . strip_tags($node['description']);

                    $offers = isset($node['offers']) ? $node['offers'] : null;
                    $offersArr = [];
                    if ($offers) {
                        if (is_array($offers) && isset($offers['@type']) && $offers['@type'] === 'AggregateOffer' && isset($offers['offers'])) {
                            $offersArr = $offers['offers'];
                        } elseif (is_array($offers) && array_keys($offers) === range(0, count($offers) - 1)) {
                            $offersArr = $offers; // already array of offers
                        } else {
                            $offersArr = [$offers];
                        }
                    }
                    $offerLines = [];
                    foreach ($offersArr as $off) {
                        if (!is_array($off)) continue;
                        $price     = isset($off['price']) ? $off['price'] : (isset($off['priceSpecification']['price']) ? $off['priceSpecification']['price'] : null);
                        $currency  = isset($off['priceCurrency']) ? $off['priceCurrency'] : (isset($off['priceSpecification']['priceCurrency']) ? $off['priceSpecification']['priceCurrency'] : null);
                        $skuOff    = isset($off['sku']) ? $off['sku'] : '';
                        $avail     = isset($off['availability']) ? $off['availability'] : '';
                        // try common variant attributes
                        $attrs = [];
                        foreach (['color','size','material','pattern'] as $k) {
                            if (isset($off[$k])) $attrs[] = $k . '=' . (is_array($off[$k]) ? json_encode($off[$k], JSON_UNESCAPED_UNICODE) : $off[$k]);
                        }
                        $offerLines[] = trim(($skuOff ? "SKU=$skuOff; " : '')
                            . ($price !== null ? "price=$price " : '')
                            . ($currency ? $currency : '')
                            . ($avail ? "; availability=$avail" : '')
                            . ($attrs ? "; " . implode(', ', $attrs) : ''));
                    }
                    if ($offerLines) {
                        $prod[] = 'Offers: ' . implode(' | ', $offerLines);
                    }
                    if ($prod) {
                        $blocks[] = implode("\n", $prod);
                    }
                }
            }
        }
    }

    // 2) WooCommerce variations from data-product_variations (inline JSON)
    if (preg_match('/data-product_variations=["\'](.+?)["\']/s', $raw, $mv)) {
        $json = html_entity_decode($mv[1], ENT_QUOTES | ENT_HTML5);
        $json = preg_replace('/&quot;/', '"', $json);
        $json = stripslashes($json);
        $vars = json_decode($json, true);
        if (is_array($vars) && $vars) {
            $lines = ['VARIATIONS:'];
            foreach ($vars as $v) {
                if (!is_array($v)) continue;
                $sku   = isset($v['sku']) ? $v['sku'] : '';
                $price = isset($v['display_price']) ? $v['display_price'] : (isset($v['price']) ? $v['price'] : '');
                $stock = isset($v['is_in_stock']) ? ($v['is_in_stock'] ? 'in_stock' : 'out_of_stock') : '';
                $attrs = [];
                if (isset($v['attributes']) && is_array($v['attributes'])) {
                    foreach ($v['attributes'] as $k => $val) {
                        $attrs[] = trim($k . ': ' . (is_array($val) ? implode('/', $val) : $val));
                    }
                }
                $lines[] = '- ' . implode('; ', array_filter([
                    $sku ? "SKU=$sku" : null,
                    $price !== '' ? "price=$price" : null,
                    $stock ? "stock=$stock" : null,
                    $attrs ? 'attrs=' . implode(', ', $attrs) : null,
                ]));
            }
            if (count($lines) > 1) {
                $blocks[] = implode("\n", $lines);
            }
        }
    }

    // 3) Specifications table (WooCommerce)
    $dom = new \DOMDocument();
    @$dom->loadHTML($raw, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $xpath = new \DOMXPath($dom);
    $rows = $xpath->query("//*[contains(@class,'woocommerce-product-attributes') or contains(@class,'shop_attributes')]//tr");
    if ($rows && $rows->length) {
        $spec = ['SPECIFICATIONS:'];
        foreach ($rows as $tr) {
            $thNode = $xpath->query('.//th', $tr)->item(0);
            $tdNode = $xpath->query('.//td', $tr)->item(0);
            if (!$thNode || !$tdNode) continue;
            $name = trim(preg_replace('/\s+/u',' ',$thNode->textContent));
            $val  = trim(preg_replace('/\s+/u',' ',$tdNode->textContent));
            if ($name !== '' && $val !== '') $spec[] = $name . ': ' . $val;
        }
        if (count($spec) > 1) $blocks[] = implode("\n", $spec);
    }

    // 4) Strip boilerplate from RAW (after we already parsed JSON‑LD)
    $cleanHtml = preg_replace('/<(script|style|nav|header|footer|aside|svg|iframe)[^>]*>.*?<\/\1>/is', ' ', $raw);

    // Append normalized structured blocks so они попали в текст
    if ($blocks) {
        $cleanHtml .= "\n\n" . implode("\n\n", $blocks);
    }

    // 5) Convert to plain text
    if (class_exists(\voku\helper\HtmlDomParser::class)) {
        $text = HtmlDomParser::str_get_html($cleanHtml)->plaintext;
    } else {
        $dom2 = new \DOMDocument();
        @$dom2->loadHTML($cleanHtml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $text = $dom2->textContent;
    }

    // collapse whitespace
    $text = preg_replace('/\s+/u', ' ', trim($text));
    $text = utf8_clean($text);
    return $text;
}

/* грубый подсчёт токенов (≈ 1 токен на 3.7 символа, более консервативно) */
function tokenCount($text)
{
    return (int) ceil(strlen($text) / 3.7);
}

/* ---------- COMMON ---------- */
function jsonResp($payload)
{
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* ---------- BACKGROUND WORKER ---------- */
function runTraining($trainingId)
{
    global $progressFile, $outputFile, $indexFile;

    $trainingId = (int)$trainingId;
    logMsg("runTraining started for training #$trainingId");

    $pdo = db();
    if (!$pdo) {
        logMsg("Failed to connect to database in CLI mode");
        return;
    }

    $tr = $pdo->prepare("SELECT * FROM trainings WHERE id=? AND status='running'");
    $tr->execute([$trainingId]);
    $training = $tr->fetch(PDO::FETCH_ASSOC);
    if (!$training) {
        logMsg("Training #$trainingId not found or not running");
        return;
    }

    $siteId = (int) $training['site_id'];
    logMsg("Training #$trainingId for site #$siteId");

    $apiKey = getOpenAIKey($pdo);
    if (!$apiKey) {
        logMsg("OpenAI API key not found in database");
        return;
    }

    $cfg = getEmbeddingConfig($pdo);
    $model = $cfg['model'];
    $pricePerM = $cfg['pricePerM'];
    // dynamic chunk: aim ≈ 1200 tokens per request (safe for rate limits)
    $chunkSize = 4800; // ≈ 4 chars per token

    $client = OpenAI::client($apiKey);

    $pageStmt = $pdo->prepare("SELECT id,url FROM pages WHERE site_id=? AND status='pending' ORDER BY id");
    $pageStmt->execute([$siteId]);
    $pages = $pageStmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare JSON data structures
    $embeddings = [];
    // Avoid duplicate embeddings for same URL within same run
    $seenChunks = [];
    // Load index or start fresh
    $index = file_exists($indexFile)
        ? json_decode(file_get_contents($indexFile), true)
        : [];
    // Initialize progress JSON
    file_put_contents($progressFile, json_encode([
        'processed_pages' => 0,
        'total_pages'     => count($pages)
    ]));

    logMsg("Found " . count($pages) . " pending pages for site #$siteId");

    if (empty($pages)) {
        logMsg("No pending pages found for training #$trainingId");
        $pdo->prepare("UPDATE trainings SET status='finished', finished_at=NOW() WHERE id=?")->execute([$trainingId]);
        return;
    }

    $done = 0;
    $cost = 0.0;
    $totalTokens = 0;

    foreach ($pages as $p) {
        $pageId = (int) $p['id'];
        $url = $p['url'];

        logMsg("Processing page $url (id=$pageId)");

        $pdo->prepare("UPDATE pages SET status='training' WHERE id=?")->execute([$pageId]);

        try {
            $html = @file_get_contents($url);
            if ($html === false) {
                throw new Exception("Cannot fetch: $url");
            }
            $html = utf8_clean($html);
            $clean = extractText($html);

            $chunks = utf8_chunks($clean, $chunkSize);
            $pageTokens = 0;

            foreach ($chunks as $chunk) {
                $chunk = trim($chunk);
                $chunk = utf8_clean($chunk);
                if (strlen($chunk) < 100) {
                    continue;
                }
                $hash = md5($chunk);
                if (isset($seenChunks[$hash])) continue;
                $seenChunks[$hash] = true;
                $tok = tokenCount($chunk);
                try {
                    $resp = $client->embeddings()->create([
                        'model' => $model,
                        'input' => $chunk,
                    ]);
                    $emb = isset($resp['data'][0]['embedding']) ? $resp['data'][0]['embedding'] : null;
                    if (!$emb) {
                        logMsg("ERR: no embedding returned for chunk of $url");
                        continue;
                    }
                } catch (Exception $e) {
                    logMsg("Error embedding chunk for $url: " . $e->getMessage());
                    continue;
                }
                $pageTokens += $tok;
                // Append full embedding data and text
                $embeddings[] = [
                    'url'        => $url,
                    'text'       => $chunk,
                    'embedding'  => $emb,
                    'tokens'     => $tok,
                    'cost'       => ($tok / 1000000) * $pricePerM,
                    'timestamp'  => date('c'),
                ];
                usleep(200000);
            }

            if ($pageTokens === 0) {
                throw new Exception("Empty/short content");
            }

            $pageCost = ($pageTokens / 1000000) * $pricePerM;
            logMsg("✅ $url — tokens=$pageTokens cost=$pageCost");

            $done++;
            $cost += $pageCost;
            $totalTokens += $pageTokens;

            $pdo->prepare(
                "UPDATE pages SET status='ready',
                                  last_trained_at=NOW(),
                                  embed_cost=?,
                                  embed_tokens=?
                 WHERE id=?"
            )->execute([$pageCost, $pageTokens, $pageId]);

            $pdo->prepare(
                "UPDATE trainings
                    SET processed_pages=?, total_cost=?
                  WHERE id=?"
            )->execute([$done, $cost, $trainingId]);

            // update JSON progress
            file_put_contents($progressFile, json_encode([
                'processed_pages' => $done,
                'total_pages'     => isset($training['total_pages']) ? $training['total_pages'] : count($pages)
            ]));

        } catch (Exception $e) {
            $pdo->prepare("UPDATE pages SET status='error' WHERE id=?")->execute([$pageId]);
            logMsg("❌ error $url : " . $e->getMessage());
        }
    }

    // Write final embeddings and index JSON
    file_put_contents($outputFile, json_encode($embeddings, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
    file_put_contents($indexFile, json_encode($index, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));

    logMsg("Training #$trainingId finished: pages=$done, tokens=$totalTokens, cost=$cost");

    $pdo->prepare(
        "UPDATE trainings
            SET status='finished', finished_at=NOW()
          WHERE id=?"
    )->execute([$trainingId]);

    logMsg("Training #$trainingId finished\n");
}

/* ---------- CLI LAUNCH ---------- */
if (isset($argv[1]) && is_numeric($argv[1])) {
    logMsg("Invoking runTraining from CLI block for training #" . $argv[1]);
    runTraining((int) $argv[1]);
    exit;
}

/* ---------- PROGRESS HANDLER ---------- */
if (isset($_GET['progress']) && isset($_GET['training_id'])) {
    $tid = (int)$_GET['training_id'];
    $pdo = db();
    $stm = $pdo->prepare("SELECT * FROM trainings WHERE id=?");
    $stm->execute([$tid]);
    $training = $stm->fetch(PDO::FETCH_ASSOC);
    jsonResp($training ?: ['error' => 'Training not found']);
}

/* ---------- STEP 1 – Показ найденных ссылок ---------- */
if (isset($_POST['urls'])) {
    $input = array_filter(array_map('trim', explode("\n", $_POST['urls'])));
    $all   = [];

    foreach ($input as $u) {
        if (preg_match('/sitemap.*\.xml$/i', $u)) {
            try {
                $sx = simplexml_load_file($u);
                foreach ($sx->url as $n) {
                    $all[] = (string) $n->loc;
                }
            } catch (Exception $e) {
                echo "<p class='text-red-400'>Ошибка карты сайта $u: {$e->getMessage()}</p>";
            }
        } else {
            $all[] = $u;
        }
    }

    $all = array_unique($all);
    $pdo = db();
    $st  = $pdo->prepare(
        "SELECT url,status,last_modified,last_trained_at FROM pages WHERE url=?"
    );

    $statuses = [];
    foreach ($all as $url) {
        $st->execute([$url]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $statuses[$url] = 'new';
        } elseif ($row['status'] === 'ready' && $row['last_modified'] <= $row['last_trained_at']) {
            $statuses[$url] = 'ready';
        } else {
            $statuses[$url] = 'update';
        }
    }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ссылки для обучения</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-900 text-white min-h-screen flex items-center justify-center px-4">
    <div class="bg-gray-800 p-8 rounded-xl shadow-2xl w-full">
        <h1 class="text-3xl font-extrabold mb-6 text-indigo-400 flex items-center"><i class="fas fa-link mr-2"></i> Всего ссылок: <?php echo count($all); ?></h1>
        <ul class="list-disc pl-6 mb-6 max-h-80 overflow-y-auto text-gray-300">
            <?php foreach ($all as $u):
                $c = $statuses[$u] === 'new'    ? 'text-red-400'
                   : ($statuses[$u] === 'update' ? 'text-yellow-400'
                   : 'text-green-400 line-through');
            ?>
            <li class="<?php echo $c; ?> hover:text-indigo-300 transition"><?php echo $u; ?></li>
            <?php endforeach; ?>
        </ul>

        <form method="post" class="space-y-4">
            <label class="block font-semibold text-indigo-300">Исключения</label>
            <textarea name="exclusions" class="w-full p-3 border rounded-lg bg-gray-700 text-white focus:ring-2 focus:ring-indigo-500"
                      placeholder="Каждый шаблон с новой строки"></textarea>

            <input type="hidden" name="pages" value="<?php echo htmlentities(json_encode($all)); ?>">
            <button name="start_ingest"
                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded w-full transition flex items-center justify-center">
                <i class="fas fa-rocket mr-2"></i> Начать обучение
            </button>
        </form>
    </div>
</body>
</html>
<?php
exit;
}

/* ---------- STEP 2 – Очередь обучения ---------- */
if (isset($_POST['start_ingest'], $_POST['pages'], $_POST['exclusions'])) {
    $pages = json_decode($_POST['pages'], true) ?: [];
    $excl  = array_filter(array_map('trim', explode("\n", $_POST['exclusions'])));

    /* фильтрация */
    $filtered = [];
    foreach ($pages as $u) {
        $skip = false;
        foreach ($excl as $ex) {
            if ($ex === '') continue;
            if ($ex[0] === '*' && substr($ex, -1) === '*') {
                $p = '/' . str_replace('\\*', '.*', preg_quote($ex, '/')) . '/';
                if (preg_match($p, $u)) { $skip = true; break; }
            } elseif (strpos($u, $ex) !== false) {
                $skip = true; break;
            }
        }
        if (!$skip) $filtered[] = $u;
    }

    if (!$filtered) {
        echo '<div class="bg-gray-800 text-red-400 p-6 rounded-lg w-full">После фильтрации не осталось страниц.</div>';
        exit;
    }

    $pdo = db();
    $pdo->beginTransaction();

    $host = parse_url($filtered[0], PHP_URL_HOST);

    $sid = $pdo->prepare("SELECT id FROM sites WHERE url=?");
    $sid->execute([$host]);
    $siteId = $sid->fetchColumn();
    if (!$siteId) {
        $pdo->prepare("INSERT INTO sites (url) VALUES (?)")->execute([$host]);
        $siteId = $pdo->lastInsertId();
    }

    $insPage = $pdo->prepare(
        "INSERT INTO pages (site_id,url,status) VALUES (?,?, 'pending')
         ON DUPLICATE KEY UPDATE status='pending'"
    );
    foreach ($filtered as $u) {
        $insPage->execute([$siteId, $u]);
    }

    $pdo->prepare(
        "INSERT INTO trainings (site_id,total_pages,status) VALUES (?,?, 'running')"
    )->execute([$siteId, count($filtered)]);
    $tid = $pdo->lastInsertId();

    $pdo->commit();

    logMsg("Starting training #$tid for site #$siteId with " . count($filtered) . " pages");

    // Redirect CLI output into the main ingest log
    $cmd = PHP_BINARY
        . ' '
        . escapeshellarg(__FILE__)
        . ' '
        . $tid
        . ' >> '
        . escapeshellarg(__DIR__ . '/../ingest.log')
        . ' 2>&1 &';
    logMsg("Launching CLI command for training #$tid: $cmd");
    shell_exec($cmd);
    logMsg("Background process for training #$tid launched; output merged into ingest.log");
    // Fallback synchronous execution if background CLI did not run
    logMsg("Fallback: running training #$tid synchronously");
    //runTraining($tid);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Обучение запущено</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script>
        const tid = <?php echo $tid; ?>;
        function poll() {
            fetch('?progress=1&training_id=' + tid)
                .then(r => r.json())
                .then(d => {
                    if (!d || !d.status) return;
                    const bar = document.getElementById('bar');
                    const txt = document.getElementById('txt');
                    const pct = d.total_pages ? (d.processed_pages / d.total_pages) * 100 : 0;
                    bar.style.width = pct + '%';
                    txt.textContent = `${d.processed_pages} из ${d.total_pages} (${pct.toFixed(1)}%)`;
                    if (d.status === 'running') {
                        setTimeout(poll, 3000);
                    } else {
                        txt.textContent = 'Обучение завершено';
                        bar.classList.add('bg-green-500');
                    }
                });
        }
        window.onload = poll;
    </script>
</head>
<body class="bg-gray-900 text-white min-h-screen flex items-center justify-center px-4">
    <div class="bg-gray-800 p-8 rounded-xl shadow-2xl w-full">
        <h1 class="text-2xl font-bold mb-4 text-indigo-400 flex items-center"><i class="fas fa-spinner fa-spin mr-2"></i> Обучение запущено (#<?php echo $tid; ?>)</h1>
        <div class="w-full bg-gray-700 h-4 rounded">
            <div id="bar" class="bg-indigo-500 h-4 rounded transition-all duration-500" style="width:0%"></div>
        </div>
        <p id="txt" class="mt-3 text-center text-gray-300">0%</p>
        <p class="mt-4 text-sm text-gray-400">Страницу можно закрыть — процесс продолжится в фоне.</p>
    </div>
</body>
</html>
<?php
exit;
}

/* ---------- INITIAL FORM ---------- */
?>
<?php /* replaced by interactive UI */ ?>
<?php
// MANAGEMENT: clear all training data
if (isset($_GET['clear'])) {
    $pdo = db();
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $pdo->exec("TRUNCATE TABLE trainings");
    $pdo->exec("TRUNCATE TABLE pages");
    $pdo->exec("TRUNCATE TABLE sites");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    array_map('unlink', glob(__DIR__ . '/../data/*.json'));
    echo "<div style='padding:20px; background:#16a34a; color:#fff; border-radius:8px;'>Все данные обучения очищены.</div>";
    exit;
}
// MANAGEMENT: show statistics
if (isset($_GET['stats'])) {
    $pdo = db();
    $rows = $pdo->query("SELECT s.id, s.url, MAX(t.finished_at) AS last_trained
                          FROM sites s LEFT JOIN trainings t ON t.site_id=s.id
                          GROUP BY s.id, s.url ORDER BY s.url")->fetchAll(PDO::FETCH_ASSOC);
    ?><!doctype html><html><head>
      <meta charset="utf-8"><script src="https://cdn.tailwindcss.com"></script>
      <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
      <title><?= htmlspecialchars(t('stats.title')) ?></title></head>
    <body class="min-h-screen bg-gradient-to-br from-gray-900 via-indigo-950 to-gray-900 text-gray-100 p-6">
      <div class="max-w-6xl mx-auto">
        <h1 class="text-2xl font-bold text-indigo-300 mb-4"><i class="fas fa-chart-pie mr-2"></i><?= htmlspecialchars(t('stats.title')) ?></h1>
        <div class="overflow-auto rounded-xl border border-white/10">
          <table class="min-w-full text-sm">
            <thead class="bg-white/5 text-gray-400">
              <tr>
                <th class="text-left py-2 px-3"><?= htmlspecialchars(t('domain')) ?></th>
                <th class="text-left py-2 px-3"><?= htmlspecialchars(t('last.training')) ?></th>
                <th class="text-left py-2 px-3"><?= htmlspecialchars(t('error.pages')) ?></th>
                <th class="text-left py-2 px-3"><?= htmlspecialchars(t('action')) ?></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/10">
            <?php foreach ($rows as $site): ?>
              <tr>
                <td class="py-2 px-3 font-medium text-indigo-200"><?= htmlspecialchars($site['url']) ?></td>
                <td class="py-2 px-3"><?= $site['last_trained'] ?: '—' ?></td>
                <td class="py-2 px-3">
                  <?php $err=$pdo->prepare("SELECT id,url FROM pages WHERE site_id=? AND status='error' LIMIT 50"); $err->execute([$site['id']]); $eps=$err->fetchAll(PDO::FETCH_ASSOC); ?>
                  <?php if ($eps): ?>
                    <ul class="space-y-1">
                      <?php foreach ($eps as $e): ?>
                        <li><a class="text-blue-400 hover:underline" href="<?= htmlspecialchars($e['url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($e['url']) ?></a> (<a class="text-indigo-300 hover:underline" href="?retrain=<?= (int)$e['id'] ?>"><?= htmlspecialchars(t('retry')) ?></a>)</li>
                      <?php endforeach; ?>
                    </ul>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td class="py-2 px-3"><a class="text-indigo-300 hover:underline" href="?retrain_site=<?= (int)$site['id'] ?>"><?= htmlspecialchars(t('train.site')) ?></a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </body></html><?php
    exit;
}
// MANAGEMENT: retrain single page
if (isset($_GET['retrain'])) {
    $pageId = (int)$_GET['retrain'];
    $pdo = db();
    $pdo->prepare("UPDATE pages SET status='pending' WHERE id=?")->execute([$pageId]);
    header("Location: ?stats=1");
    exit;
}
// MANAGEMENT: retrain entire site
if (isset($_GET['retrain_site'])) {
    $siteId = (int)$_GET['retrain_site'];
    $pdo = db();
    // Пометить все страницы сайта как pending
    $pdo->prepare("UPDATE pages SET status='pending' WHERE site_id=?")->execute([$siteId]);
    // Создать новую запись обучения: корректно посчитать количество страниц для этого сайта
    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE site_id=?");
    $cntStmt->execute([$siteId]);
    $count = (int) $cntStmt->fetchColumn();
    $pdo->prepare("INSERT INTO trainings (site_id, total_pages, status) VALUES (?, ?, 'running')")
        ->execute([$siteId, $count]);
    header("Location: ?stats=1");
    exit;
}
?>
<div class="space-y-6">
  <div class="bg-gradient-to-br from-indigo-900/40 via-indigo-800/20 to-purple-900/30 border border-white/10 rounded-xl p-6 mb-6">
    <h3 class="text-lg font-semibold text-indigo-300 mb-3"><i class="fas fa-chart-line mr-2"></i><?= htmlspecialchars(t('summary.title')) ?></h3>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <div class="p-4 rounded-lg bg-white/5 border border-white/10">
        <div class="text-sm text-gray-400"><?= htmlspecialchars(t('total.sites')) ?></div>
        <div id="sumSites" class="text-2xl font-bold text-indigo-200">—</div>
      </div>
      <div class="p-4 rounded-lg bg-white/5 border border-white/10">
        <div class="text-sm text-gray-400"><?= htmlspecialchars(t('trained.pages')) ?></div>
        <div id="sumPages" class="text-2xl font-bold text-indigo-200">—</div>
      </div>
      <div class="p-4 rounded-lg bg-white/5 border border-white/10">
        <div class="text-sm text-gray-400"><?= htmlspecialchars(t('ongoing')) ?></div>
        <div id="sumRunning" class="text-2xl font-bold text-indigo-200">—</div>
      </div>
    </div>
  </div>
  <div class="bg-white/5 border border-white/10 rounded-xl p-4">
    <h3 class="text-indigo-300 font-semibold mb-3 flex items-center gap-2"><i class="fas fa-sliders-h"></i> <?= htmlspecialchars(t('training.manage')) ?></h3>
    <div class="flex flex-wrap gap-3">
      <a href="?clear=1" class="inline-flex items-center gap-2 px-3 py-2 rounded bg-red-600 hover:bg-red-700 text-white text-sm">
        <i class="fas fa-trash-alt"></i> <?= htmlspecialchars(t('clear.all')) ?>
      </a>
      <button id="btnOpenStats" type="button" class="inline-flex items-center gap-2 px-3 py-2 rounded bg-blue-600 hover:bg-blue-700 text-white text-sm">
        <i class="fas fa-chart-bar"></i> <?= htmlspecialchars(t('view.stats')) ?>
      </button>
    </div>
  </div>

<div class="bg-white/5 border border-white/10 rounded-xl p-6" id="ingestStepper">
  <h3 class="text-xl font-semibold text-indigo-300 mb-4 flex items-center gap-2"><i class="fas fa-file-import"></i> <?= htmlspecialchars(t('step.urls')) ?></h3>

  <!-- Stepper header -->
  <div class="flex items-center gap-4 mb-6 text-sm">
    <div class="step-dot w-8 h-8 grid place-items-center rounded-full bg-indigo-600 text-white" data-step="1">1</div>
    <div class="flex-1 border-t border-white/10"></div>
    <div class="step-dot w-8 h-8 grid place-items-center rounded-full bg-white/10 text-gray-300" data-step="2">2</div>
    <div class="flex-1 border-t border-white/10"></div>
    <div class="step-dot w-8 h-8 grid place-items-center rounded-full bg-white/10 text-gray-300" data-step="3">3</div>
  </div>

  <!-- Step 1: URLs & Preview -->
  <section id="step1" class="space-y-4">
    <p class="text-sm text-gray-400"><?= htmlspecialchars(t('url.input')) ?></p>
    <textarea id="ingest_urls" class="w-full h-40 p-3 bg-gray-800/60 border border-white/10 rounded focus:ring-2 focus:ring-indigo-500" placeholder="<?= htmlspecialchars(t('paste.links')) ?>"></textarea>
    <div class="flex items-center gap-3">
      <button id="btnPreview" class="inline-flex items-center gap-2 px-4 py-2 rounded bg-gray-700 hover:bg-gray-600 text-white"><i class="fas fa-eye"></i> <?= htmlspecialchars(t('preview')) ?></button>
      <span id="previewInfo" class="text-sm text-gray-400 hidden"></span>
      <button id="toStep2" class="ml-auto inline-flex items-center gap-2 px-4 py-2 rounded bg-indigo-600 text-white opacity-60 cursor-not-allowed"><i class="fas fa-arrow-right"></i> <?= htmlspecialchars(t('next')) ?></button>
    </div>
    <div id="previewWrap" class="hidden mt-4">
      <ul id="previewList" class="max-h-56 overflow-auto space-y-1 text-gray-300 text-sm"></ul>
      <div class="flex flex-wrap gap-3 mt-3 text-sm">
        <span class="px-2 py-1 rounded bg-white/5 border border-white/10"><strong id="cntTotal">0</strong> <?= htmlspecialchars(t('filtered.links')) ?> <span id="cntOf" class="opacity-70"></span></span>
        <span class="px-2 py-1 rounded bg-green-500/10 text-green-300 border border-green-500/20"><strong id="cntReady">0</strong> <?= htmlspecialchars(t('status.ready')) ?></span>
        <span class="px-2 py-1 rounded bg-yellow-500/10 text-yellow-300 border border-yellow-500/20"><strong id="cntUpdate">0</strong> <?= htmlspecialchars(t('status.update')) ?></span>
        <span class="px-2 py-1 rounded bg-red-500/10 text-red-300 border border-red-500/20"><strong id="cntNew">0</strong> <?= htmlspecialchars(t('status.new')) ?></span>
      </div>
    </div>
  </section>

  <!-- Step 2: Exclusions (live preview) -->
  <section id="step2" class="hidden space-y-4">
    <h4 class="font-semibold text-indigo-300"><i class="fas fa-filter"></i> <?= htmlspecialchars(t('step.exclusions')) ?></h4>
    <textarea id="ingest_exclusions" class="w-full h-36 p-3 bg-gray-800/60 border border-white/10 rounded focus:ring-2 focus:ring-indigo-500" placeholder="<?= htmlspecialchars(t('exclusions.placeholder')) ?>"></textarea>
    <p class="text-xs text-gray-400"><?= htmlspecialchars(t('exclusions.examples')) ?></p>
    <div class="mt-2 flex flex-wrap gap-2 text-xs">
      <code class="ex-sample px-2 py-1 bg-white/5 border border-white/10 rounded">/\.(jpg|png|gif|svg|webp)$/i</code>
      <code class="ex-sample px-2 py-1 bg-white/5 border border-white/10 rounded">/\/tag\//</code>
      <code class="ex-sample px-2 py-1 bg-white/5 border border-white/10 rounded">/\/category\//</code>
      <code class="ex-sample px-2 py-1 bg-white/5 border border-white/10 rounded">/\?(utm_|fbclid|gclid)/i</code>
      <code class="ex-sample px-2 py-1 bg-white/5 border border-white/10 rounded">/\/page\/\d+\//</code>
      <code class="ex-sample px-2 py-1 bg-white/5 border border-white/10 rounded">/#/</code>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm text-gray-300">
      <div id="domainStats"></div>
    </div>
    <div class="flex gap-3">
      <button id="backTo1" class="inline-flex items-center gap-2 px-4 py-2 rounded ring-1 ring-white/10 text-gray-300 hover:bg-white/5"><i class="fas fa-arrow-left"></i> <?= htmlspecialchars(t('back')) ?></button>
      <button id="toStep3" class="ml-auto inline-flex items-center gap-2 px-4 py-2 rounded bg-indigo-600 text-white"><i class="fas fa-arrow-right"></i> <?= htmlspecialchars(t('next')) ?></button>
    </div>
  </section>

  <!-- Step 3: Summary & Mode -->
  <section id="step3" class="hidden space-y-4">
    <h4 class="font-semibold text-indigo-300 flex items-center gap-2"><i class="fas fa-list-check"></i><span><?= htmlspecialchars(t('summary.review')) ?></span></h4>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
      <div class="p-3 rounded bg-green-500/10 text-green-300 border border-green-500/20"><div class="flex items-center gap-2"><i class="fa-solid fa-circle-check"></i><span><?= htmlspecialchars(t('status.ready')) ?></span></div><div class="text-2xl font-bold" id="sumReady">0</div></div>
      <div class="p-3 rounded bg-yellow-500/10 text-yellow-300 border border-yellow-500/20"><div class="flex items-center gap-2"><i class="fa-solid fa-arrows-rotate"></i><span><?= htmlspecialchars(t('status.update')) ?></span></div><div class="text-2xl font-bold" id="sumUpdate">0</div></div>
      <div class="p-3 rounded bg-red-500/10 text-red-300 border border-red-500/20"><div class="flex items-center gap-2"><i class="fa-solid fa-circle-plus"></i><span><?= htmlspecialchars(t('status.new')) ?></span></div><div class="text-2xl font-bold" id="sumNew">0</div></div>
    </div>
    <div class="mt-2 text-sm text-gray-300">
      <div class="font-semibold mb-2"><?= htmlspecialchars(t('process.mode')) ?></div>
      <label class="block flex items-start gap-2 py-1">
        <input type="radio" name="procMode" value="smart" checked class="mt-1">
        <span>
          <span class="flex items-center gap-2 font-medium"><i class="fa-solid fa-wand-magic-sparkles text-indigo-300"></i><?= htmlspecialchars(t('mode.smart')) ?></span>
          <span class="block text-xs opacity-70 leading-snug"><?= htmlspecialchars(t('mode.smart.desc')) ?></span>
        </span>
      </label>
      <label class="block flex items-start gap-2 py-1">
        <input type="radio" name="procMode" value="new_only" class="mt-1">
        <span>
          <span class="flex items-center gap-2 font-medium"><i class="fa-solid fa-circle-plus text-red-300"></i><?= htmlspecialchars(t('mode.new')) ?></span>
          <span class="block text-xs opacity-70 leading-snug"><?= htmlspecialchars(t('mode.new.desc')) ?></span>
        </span>
      </label>
      <label class="block flex items-start gap-2 py-1">
        <input type="radio" name="procMode" value="reprocess_all" class="mt-1">
        <span>
          <span class="flex items-center gap-2 font-medium"><i class="fa-solid fa-rotate-right text-yellow-300"></i><?= htmlspecialchars(t('mode.all')) ?></span>
          <span class="block text-xs opacity-70 leading-snug"><?= htmlspecialchars(t('mode.all.desc')) ?></span>
        </span>
      </label>
    </div>
    <div class="mt-2 text-indigo-200 flex items-center gap-2"><i class="fa-solid fa-list-check"></i><strong><?= htmlspecialchars(t('will.process')) ?></strong>: <span id="willProcess">0</span> <?= htmlspecialchars(t('pages')) ?></div>
    <div class="flex gap-3">
      <button id="backTo2" class="inline-flex items-center gap-2 px-4 py-2 rounded ring-1 ring-white/10 text-gray-300 hover:bg-white/5"><i class="fas fa-arrow-left"></i> <?= htmlspecialchars(t('back')) ?></button>
      <button id="btnStart" class="ml-auto inline-flex items-center gap-2 px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-700 text-white"><i class="fas fa-rocket"></i> <?= htmlspecialchars(t('start')) ?></button>
    </div>
  </section>
</div>

<!-- Progress area (shown after Start) -->
<div id="progressWrap" class="hidden mt-6">
  <div class="p-4 rounded-lg bg-gradient-to-r from-indigo-900/40 to-purple-900/30 border border-white/10">
    <div class="flex items-center justify-between">
      <div class="flex items-center gap-2 text-indigo-200">
        <i class="fas fa-rocket"></i>
        <span><?= htmlspecialchars(t('ongoing')) ?></span>
      </div>
      <div id="progTxt" class="text-sm text-gray-300">0 <?= htmlspecialchars(t('of')) ?> 0 (0%)</div>
    </div>
    <div class="mt-3 h-2 bg-white/10 rounded overflow-hidden">
      <div id="bar" class="h-2 bg-indigo-500" style="width:0%"></div>
    </div>
  </div>
</div>

<div class="bg-white/5 border border-white/10 rounded-xl p-6" data-card="site-stats">
  <div class="flex items-center justify-between mb-3">
    <h3 class="text-xl font-semibold text-indigo-300"><i class="fas fa-chart-pie mr-2"></i> <?= htmlspecialchars(t('stats.by_site')) ?></h3>
    <button id="btnStatsRefresh" class="px-3 py-1.5 rounded bg-gray-700 hover:bg-gray-600 text-white text-sm"><i class="fas fa-sync-alt"></i> <?= htmlspecialchars(t('refresh')) ?></button>
  </div>
  <div class="overflow-auto">
    <table class="min-w-full text-sm">
      <thead class="text-gray-400">
        <tr>
          <th class="text-left py-2 pr-4"><?= htmlspecialchars(t('site')) ?></th>
          <th class="text-right py-2 pr-4"><?= htmlspecialchars(t('trainings')) ?></th>
          <th class="text-right py-2 pr-4"><?= htmlspecialchars(t('pages')) ?></th>
          <th class="text-right py-2 pr-4"><?= htmlspecialchars(t('processed')) ?></th>
          <th class="text-left py-2"><?= htmlspecialchars(t('last.training')) ?></th>
        </tr>
      </thead>
      <tbody id="statsBody" class="text-gray-200"></tbody>
    </table>
  </div>
</div>

<script>
(function(){
  const ENDPOINT = '<?= addslashes(defined('INGEST_ENDPOINT') ? INGEST_ENDPOINT : (dirname($_SERVER['SCRIPT_NAME'])."/ingest.php")) ?>';
  const elUrls = document.getElementById('ingest_urls');
  const elExcl = document.getElementById('ingest_exclusions');
  const btnPreview = document.getElementById('btnPreview');
  const btnStart = document.getElementById('btnStart');
  const wrapPrev = document.getElementById('previewWrap');
  const ulPrev = document.getElementById('previewList');
  const cntTotal = document.getElementById('cntTotal');
  const cntNew = document.getElementById('cntNew');
  const cntUpdate = document.getElementById('cntUpdate');
  const cntReady = document.getElementById('cntReady');
  const cntOf = document.getElementById('cntOf');
  const progWrap = document.getElementById('progressWrap');
  const bar = document.getElementById('bar');
  const progTxt = document.getElementById('progTxt');
  const statsBody = document.getElementById('statsBody');
  const btnOpenStats = document.getElementById('btnOpenStats');
  const sumSites = document.getElementById('sumSites');
  const sumPages = document.getElementById('sumPages');
  const sumRunning = document.getElementById('sumRunning');
  // Stepper elements
  const step1 = document.getElementById('step1');
  const step2 = document.getElementById('step2');
  const step3 = document.getElementById('step3');
  const toStep2 = document.getElementById('toStep2');
  const toStep3 = document.getElementById('toStep3');
  const backTo1 = document.getElementById('backTo1');
  const backTo2 = document.getElementById('backTo2');
  const previewInfo = document.getElementById('previewInfo');
  const sumReady = document.getElementById('sumReady');
  const sumUpdate = document.getElementById('sumUpdate');
  const sumNew = document.getElementById('sumNew');
  const willProcess = document.getElementById('willProcess');

  let rawList = []; // [{url, status}]
  let totalRaw = 0;

  function fd(obj){ const f=new FormData(); for (const k in obj) f.append(k,obj[k]); return f; }
  function li(url, status){
    const cls = status==='ready'?'text-green-300':(status==='update'?'text-yellow-300':'text-red-300');
    return `<li class="${cls}">${url}</li>`;
  }
  function parsePatterns(text){
    return text.split(/\n+/).map(s=>s.trim()).filter(Boolean).map(s=>{
      if(s.startsWith('/') && s.lastIndexOf('/')>0){
        const last = s.lastIndexOf('/');
        const body = s.slice(1,last); const flags = s.slice(last+1);
        try { return {type:'re', re:new RegExp(body, flags||'')}; } catch(e){ return {type:'substr', s}; }
      }
      return {type:'substr', s};
    });
  }
  function applyFilters(list){
    const pats = parsePatterns(elExcl.value||'');
    if(!pats.length) return list.slice();
    return list.filter(x=>{
      for(const p of pats){
        if(p.type==='re' && p.re.test(x.url)) return false;
        if(p.type==='substr' && p.s && x.url.includes(p.s)) return false;
      }
      return true;
    });
  }
  function domainOf(u){ try { return new URL(u).hostname; } catch(e){ return (u||'').replace(/^https?:\/\//,'').split('/')[0]; } }
  function updateDomainStats(list){
    const map = new Map();
    list.forEach(x=>{ const d = domainOf(x.url); map.set(d,(map.get(d)||0)+1); });
    const rows = Array.from(map.entries()).sort((a,b)=>b[1]-a[1]).slice(0,20)
      .map(([d,c])=>`<div class="flex items-center justify-between bg-white/5 border border-white/10 rounded px-3 py-1"><span class="truncate">${d}</span><span class="font-mono">${c}</span></div>`);
    document.getElementById('domainStats').innerHTML = rows.join('');
  }
  function recount(list){
    const c = {new:0, update:0, ready:0};
    list.forEach(x=>{ c[x.status] = (c[x.status]||0)+1; });
    cntTotal.textContent = list.length;
    cntOf.textContent = ' (<?= htmlspecialchars(t('of')) ?> ' + (totalRaw||0) + ')';
    cntNew.textContent = c.new||0; cntUpdate.textContent = c.update||0; cntReady.textContent = c.ready||0;
    ulPrev.innerHTML = list.map(x=>li(x.url, x.status)).join('');
    updateDomainStats(list);
  }
  function onExclChange(){ recount(applyFilters(rawList)); if(!step3.classList.contains('hidden')) recalcSummary(); updateDomainStats(applyFilters(rawList)); }

  function preview(){
    fetch(ENDPOINT, {method:'POST', body: fd({ajax:'1', action:'preview', urls: elUrls.value})})
      .then(r=>r.text()).then(txt=>{ let d; try{ d=JSON.parse(txt); } catch(e){ console.error('Preview JSON parse failed:', txt); return; }
        if(!d||!d.ok) return;
        rawList = d.list||[]; totalRaw = d.total||rawList.length;
        previewInfo.classList.remove('hidden');
        previewInfo.textContent = `${d.total||rawList.length} URLs found`;
        toStep2.classList.remove('opacity-60','cursor-not-allowed');
        wrapPrev.classList.remove('hidden');
        recount(applyFilters(rawList));
      });
  }
  function poll(tid){
    fetch(ENDPOINT + '?progress=1&training_id=' + encodeURIComponent(tid))
      .then(r=>r.text()).then(txt=>{ let d; try{ d=JSON.parse(txt); } catch(e){ console.error('Progress JSON parse failed:', txt); return; }
        if(!d||!d.status) return;
        const pct = d.total_pages ? (d.processed_pages/d.total_pages*100) : 0;
        if (bar) bar.style.width = pct.toFixed(1) + '%';
        if (progTxt) progTxt.textContent = `${d.processed_pages||0} <?= htmlspecialchars(t('of')) ?> ${d.total_pages||0} (${pct.toFixed(1)}%)`;
        if(d.status==='running') setTimeout(()=>poll(tid), 3000);
      });
  }
  function start(){
    const mode = (document.querySelector('input[name="procMode"]:checked')||{value:'smart'}).value;
    progWrap && progWrap.classList.remove('hidden');
    fetch(ENDPOINT, {method:'POST', body: fd({ajax:'1', action:'start', urls: elUrls.value, exclusions: elExcl.value, mode})})
      .then(r=>r.text()).then(txt=>{ let d; try{ d=JSON.parse(txt); } catch(e){ console.error('Start JSON parse failed:', txt); return; } if(d&&d.ok&&d.tid){ poll(d.tid); refreshStats(); } });
  }
  function refreshStats(){
    fetch(ENDPOINT, {method:'POST', body: fd({ajax:'1', action:'stats'})})
      .then(r=>r.text())
      .then(txt=>{ let d; try { d = JSON.parse(txt); } catch(e){ console.error('Stats JSON parse failed:', txt); return; }
        if(!d||!d.ok) return; const rows=d.rows||[];
        statsBody.innerHTML = rows.map(x=>`<tr>
            <td class="py-2 pr-4">${x.url||'—'}</td>
            <td class="py-2 pr-4 text-right">${x.trainings||0}</td>
            <td class="py-2 pr-4 text-right">${x.total_pages||0}</td>
            <td class="py-2 pr-4 text-right">${x.processed||0}</td>
            <td class="py-2 text-left">${x.last_trained||'—'}</td>
          </tr>`).join('');
      });
  }
  // Stepper implementation
  function setStep(n){
    [step1,step2,step3].forEach((el,i)=> el.classList.toggle('hidden', (i+1)!==n));
    document.querySelectorAll('.step-dot').forEach((d,i)=>{
      d.classList.toggle('bg-indigo-600', i+1<=n); d.classList.toggle('text-white', i+1<=n);
      d.classList.toggle('bg-white/10', i+1>n); d.classList.toggle('text-gray-300', i+1>n);
    });
  }
  function countsFrom(list){ const c={ready:0,update:0,new:0}; list.forEach(x=>c[x.status]++); return c; }
  function recalcSummary(){
    const filtered = applyFilters(rawList);
    const c = countsFrom(filtered);
    sumReady.textContent=c.ready; sumUpdate.textContent=c.update; sumNew.textContent=c.new;
    const mode = (document.querySelector('input[name="procMode"]:checked')||{value:'smart'}).value;
    let will = 0; filtered.forEach(x=>{ if(mode==='new_only' && x.status!=='new') return; if(mode==='smart' && x.status==='ready') return; will++; });
    willProcess.textContent = will;
  }
  document.querySelectorAll('input[name="procMode"]').forEach(r=> r.addEventListener('change', recalcSummary));
  function loadSummary(){
    fetch(ENDPOINT, {method:'POST', body: fd({ajax:'1', action:'summary'})})
      .then(r=>r.text())
      .then(txt=>{ let d; try { d = JSON.parse(txt); } catch(e){ console.error('Summary JSON parse failed:', txt); return; }
        if(!d||!d.ok) return; sumSites.textContent=d.sites||0; sumPages.textContent=d.trained||0; sumRunning.textContent=d.running||0; });
  }

  let exclDebounce; elExcl && elExcl.addEventListener('input', ()=>{ clearTimeout(exclDebounce); exclDebounce = setTimeout(onExclChange, 300); });
  document.addEventListener('click', (e)=>{ if(e.target.classList.contains('ex-sample')){ e.preventDefault(); const v = elExcl.value; elExcl.value = (v? v+"\n" : '') + e.target.textContent; elExcl.dispatchEvent(new Event('input')); }});
  btnPreview && btnPreview.addEventListener('click', preview);
  btnStart && btnStart.addEventListener('click', start);
  // Stepper navigation
  toStep2 && toStep2.addEventListener('click', ()=>{ if(toStep2.classList.contains('cursor-not-allowed')) return; setStep(2); });
  backTo1 && backTo1.addEventListener('click', ()=> setStep(1));
  toStep3 && toStep3.addEventListener('click', ()=>{ recalcSummary(); setStep(3); });
  backTo2 && backTo2.addEventListener('click', ()=> setStep(2));
  document.getElementById('btnStatsRefresh')?.addEventListener('click', refreshStats);
  btnOpenStats && btnOpenStats.addEventListener('click', function(){ document.querySelector('[data-card="site-stats"]').scrollIntoView({behavior:'smooth', block:'start'}); refreshStats(); });
  refreshStats();
  loadSummary();
})();
</script>