<?php
declare(strict_types=1);
@set_time_limit(200);
@ini_set('memory_limit', '768M');
@ini_set('max_execution_time', '200');

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = rtrim(dirname($scriptName), '/\\');
if ($basePath === '.' || $basePath === '/' || $basePath === '\\') $basePath = '';
$GLOBALS['basePath'] = $basePath;

define('DATA_ROOT', __DIR__ . '/data');
define('DB_PATH', DATA_ROOT . '/artdb.sqlite');
define('IMG_ROOT', DATA_ROOT . '/images');
define('AGNES_BASE', 'https://apihub.agnes-ai.com/v1');
define('AGNES_TEXT_MODEL', 'agnes-3.0-flash');
define('AGNES_TEXT_MODEL_FALLBACK', 'agnes-2.5-flash');
define('AGNES_IMG_MODEL', 'agnes-image-2.5-flash');
define('THUMB_WIDTH', 360);

session_start();
$sessionUserId = (int)($_SESSION['user_id'] ?? 1);
$_SESSION['user_id'] = $sessionUserId;
session_write_close();

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_ROOT)) @mkdir(DATA_ROOT, 0755, true);
    if (!is_dir(IMG_ROOT)) @mkdir(IMG_ROOT, 0755, true);
    if (!is_file(DATA_ROOT . '/.htaccess')) {
        @file_put_contents(DATA_ROOT . '/.htaccess',
            "Options -Indexes\n<FilesMatch \"\\.(sqlite|sqlite3|db|log|key_offset)$\">\nRequire all denied\n</FilesMatch>\n");
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA busy_timeout=15000');

    $hasOldSchema = false;
    try {
        $cols = $pdo->query("PRAGMA table_info(artists)")->fetchAll();
        if ($cols) {
            $names = array_column($cols, 'name');
            if (!in_array('batch_id', $names, true) || !in_array('kind', $names, true)) $hasOldSchema = true;
        }
        $cols = $pdo->query("PRAGMA table_info(images)")->fetchAll();
        if ($cols) {
            $names = array_column($cols, 'name');
            if (!in_array('parent_id', $names, true)) $hasOldSchema = true;
        }
    } catch (Throwable $e) {}
    if ($hasOldSchema) {
        $pdo->exec("DROP TABLE IF EXISTS images");
        $pdo->exec("DROP TABLE IF EXISTS artists");
        $pdo->exec("DROP TABLE IF EXISTS batches");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, key TEXT NOT NULL UNIQUE, rpm INTEGER DEFAULT 10, created_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS batches (id INTEGER PRIMARY KEY AUTOINCREMENT, input_text TEXT, image_desc TEXT, target_famous INTEGER DEFAULT 200, target_mix INTEGER DEFAULT 200, ratio TEXT DEFAULT '9:16', size TEXT DEFAULT '1K', created_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS artists (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER NOT NULL, name TEXT NOT NULL, kind TEXT NOT NULL, mix_of TEXT, created_at TEXT DEFAULT (datetime('now')), UNIQUE(batch_id, name))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS images (id INTEGER PRIMARY KEY AUTOINCREMENT, batch_id INTEGER NOT NULL, artist_id INTEGER NOT NULL, prompt TEXT NOT NULL, image_url TEXT, thumb_url TEXT, parent_id INTEGER, status TEXT DEFAULT 'pending', error TEXT, attempts INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_art_batch ON artists(batch_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_img_batch ON images(batch_id, parent_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_img_status ON images(batch_id, status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_img_parent ON images(parent_id)");
    return $pdo;
}

function respond(array $d, int $c = 200): void {
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function body(): array {
    $r = file_get_contents('php://input');
    if ($r === false || $r === '') return [];
    $j = json_decode($r, true);
    return is_array($j) ? $j : [];
}
function sanitizeKeys(array $keys): array {
    $out = [];
    foreach ($keys as $k) {
        if (!is_string($k)) continue;
        $k = trim($k);
        if (strlen($k) < 20 || strpos($k, 'sk-') !== 0) continue;
        $out[] = $k;
    }
    return array_values(array_unique($out));
}
function ensureUserDir(int $uid): string {
    $d = IMG_ROOT . '/u' . $uid;
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}
function urlSafe(string $p): string {
    if ($p === '') return '';
    return implode('/', array_map(fn($s) => $s === '' ? '' : rawurlencode($s), explode('/', $p)));
}
function publicUrl(int $userId, string $fn): string {
    return urlSafe($GLOBALS['basePath']) . '/data/images/u' . $userId . '/' . rawurlencode($fn);
}

class AgnesHttpException extends RuntimeException {
    public $httpCode;
    public function __construct(string $m, int $c = 0) { parent::__construct($m); $this->httpCode = $c; }
}

function agnesCall(string $path, array $payload, string $apiKey, int $timeout): array {
    $ch = curl_init(AGNES_BASE . $path);
    if ($ch === false) throw new RuntimeException('curl_init failed');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('Curl err ' . $errno . ': ' . $err);
    $data = json_decode($res, true);
    if ($code >= 400) {
        $msg = $data['error']['message'] ?? substr($res, 0, 250);
        throw new AgnesHttpException('Agnes HTTP ' . $code . ': ' . $msg, $code);
    }
    return is_array($data) ? $data : [];
}

function agnesRun(string $path, array $payload, array $keys, int $timeout): array {
    $n = count($keys);
    if ($n === 0) throw new RuntimeException('Aucune clé');
    $key = $keys[0];
    if (strpos($path, 'chat/completions') === false) return agnesCall($path, $payload, $key, $timeout);
    $payload['model'] = AGNES_TEXT_MODEL;
    try {
        return agnesCall($path, $payload, $key, $timeout);
    } catch (AgnesHttpException $e) {
        if ($e->httpCode === 400 || $e->httpCode === 404) {
            $payload['model'] = AGNES_TEXT_MODEL_FALLBACK;
            return agnesCall($path, $payload, $key, $timeout);
        }
        throw $e;
    }
}

function makeThumb(string $path): ?string {
    if (!function_exists('imagecreatefromstring')) return null;
    $data = @file_get_contents($path);
    if (!$data) return null;
    $im = @imagecreatefromstring($data);
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im);
    if ($w <= THUMB_WIDTH) { imagedestroy($im); return null; }
    $th = (int)round($h * THUMB_WIDTH / $w);
    $t = imagecreatetruecolor(THUMB_WIDTH, $th);
    imagecopyresampled($t, $im, 0, 0, 0, 0, THUMB_WIDTH, $th, $w, $h);
    $out = preg_replace('/\.\w+$/', '', $path) . '_t.jpg';
    imagejpeg($t, $out, 78);
    imagedestroy($im); imagedestroy($t);
    return $out;
}

function downloadImage(string $url, int $userId, string $label): array {
    $dir = ensureUserDir($userId);
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $label);
    if ($safe === '') $safe = 'img';
    if (preg_match('#^data:image/(\w+);base64,#', $url, $m)) {
        $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
        $fn = $safe . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        $bin = base64_decode(substr($url, strlen($m[0])), true);
        if ($bin === false || strlen($bin) < 100) throw new RuntimeException('base64 invalide');
        file_put_contents($dir . '/' . $fn, $bin);
    } else {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http','https'], true) || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('URL invalide');
        }
        $ext = 'png';
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && preg_match('/\.(jpg|jpeg|png|webp)$/i', $path, $mm)) {
            $ext = strtolower($mm[1]); if ($ext === 'jpeg') $ext = 'jpg';
        }
        $fn = $safe . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
        $fp = fopen($dir . '/' . $fn, 'wb');
        $ch = curl_init($url);
        $bytes = 0;
        curl_setopt_array($ch, [
            CURLOPT_WRITEFUNCTION => function($c, $chunk) use ($fp, &$bytes) {
                $bytes += strlen($chunk);
                if ($bytes > 25 * 1024 * 1024) return 0;
                return fwrite($fp, $chunk);
            },
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch); fclose($fp);
        if (!$ok || $code >= 400 || filesize($dir . '/' . $fn) < 100) {
            @unlink($dir . '/' . $fn);
            throw new RuntimeException('Téléchargement échoué (' . $code . ')');
        }
    }
    $thumbPath = makeThumb($dir . '/' . $fn);
    return [publicUrl($userId, $fn), $thumbPath ? publicUrl($userId, basename($thumbPath)) : null];
}

function parseEssence(string $desc): array {
    $out = ['theme'=>'', 'composition'=>'', 'characters'=>'', 'style'=>'', 'text'=>''];
    if ($desc === '') return $out;
    $sections = ['THEME', 'COMPOSITION', 'CHARACTERS', 'STYLE', 'TEXT'];
    foreach ($sections as $i => $sec) {
        $next = $sections[$i + 1] ?? null;
        $pattern = '/' . $sec . '\s*:\s*(.*?)(?=' . ($next ? '\n' . $next . '\s*:' : '$') . ')/is';
        if (preg_match($pattern, $desc, $m)) {
            $val = trim(preg_replace('/\s+/', ' ', $m[1]));
            $out[strtolower($sec)] = $val;
        }
    }
    return $out;
}

/* ============================================================
   PROMPT — VRAI TABLEAU + TEXTE MINIMAL
   ============================================================ */
function buildStyleBlock(array $a): string {
    if ($a['kind'] === 'famous') {
        return "STRICT STYLE — A FINISHED ORIGINAL ARTWORK, as if {$a['name']} personally created this exact subject as a signed piece from their own portfolio.\n"
             . "Medium and materiality: respect {$a['name']}'s actual body of work (oil on canvas, gouache, ink, watercolor, acrylic, engraving, lithography, digital painting — choose the medium this artist historically worked in). Show its characteristic surface traces: brushwork, canvas grain, ink bleed, watercolor blooms, halftone dots.\n"
             . "Authentic palette, technique, mark-making, lighting logic and emotional register.\n"
             . "Composition: subject placement, framing, negative space, depth and focal hierarchy — exactly as {$a['name']} would have arranged this scene for a genuine piece.\n"
             . "Result: looks like a REAL PHYSICAL ARTWORK or museum/gallery piece — NOT an AI render, NOT a photograph, NOT generic digital illustration.";
    }
    $mixArr = json_decode((string)($a['mix_of'] ?? ''), true) ?: [];
    if (count($mixArr) >= 3) {
        return "STRICT STYLE — A FINISHED ORIGINAL ARTWORK merging the three distinct visual signatures of {$mixArr[0]}, {$mixArr[1]} and {$mixArr[2]}.\n"
             . "Interweave their authentic media and materiality (respect each artist's actual body of work — oil, gouache, ink, watercolor, engraving, digital painting — with their surface traces), their palettes, mark-making, lighting logic and compositional conventions into ONE unified hybrid style.\n"
             . "Each of the three artists must be visibly present in the result.\n"
             . "Composition: a well-balanced arrangement as a genuine piece would be composed — subject placement, negative space, depth, focal hierarchy.\n"
             . "Result: looks like a REAL PHYSICAL ARTWORK — NOT an AI render, NOT a photograph.";
    }
    return "STRICT STYLE — A FINISHED ORIGINAL ARTWORK, highly stylized illustration with strong visual identity, authentic medium and materiality, cinematic lighting, distinctive palette, well-composed like a genuine gallery piece. No photorealism.";
}

function buildPromptForArtist(array $a, string $userPrompt, string $imageDesc): string {
    $e = parseEssence($imageDesc);
    $brief = '';
    if ($userPrompt !== '') $brief .= "BRIEF: " . $userPrompt . "\n";
    if ($e['theme'] !== '') $brief .= "THEME: " . $e['theme'] . "\n";
    if ($e['composition'] !== '') $brief .= "COMPOSITION: " . $e['composition'] . "\n";
    if ($e['characters'] !== '' && strtolower($e['characters']) !== 'none') $brief .= "CHARACTERS: " . $e['characters'] . "\n";

    $textVal = trim((string)($e['text'] ?? ''));
    $hasText = $textVal !== ''
        && strtolower($textVal) !== 'none'
        && strtolower($textVal) !== 'aucun'
        && mb_strlen($textVal) <= 30;

    $brief = trim($brief);
    if ($brief === '') $brief = "Reinterpret the essence of the reference freely.";

    $textSection = '';
    $techText = 'No text, no lettering, no typography.';
    if ($hasText) {
        $textSection = "\n\nOPTIONAL MINOR TEXT — rendered SMALL, discreet, integrated into the composition as a SECONDARY element only. Never a focal point. Never covering the subject. Never dominant. Max ~5% of the canvas area: \"{$textVal}\"";
        $techText = 'Any text is small and integrated as a minor element — no banners, no overlays, no giant lettering.';
    }

    return buildStyleBlock($a)
        . "\n\nREFERENCE ESSENCE — REINTERPRET FREELY through the artist's own vision. Do NOT copy the reference literally. Capture its essence and translate it entirely into the artist's visual language:\n"
        . $brief
        . $textSection
        . "\n\nTECHNICAL: full composition, well-balanced, museum-quality presentation. {$techText} No watermark, no signature, no frame, no border, no UI, no caption.";
}

function materializePending(PDO $pdo, int $batchId): int {
    $bst = $pdo->prepare("SELECT input_text, image_desc FROM batches WHERE id=?");
    $bst->execute([$batchId]);
    $batch = $bst->fetch();
    if (!$batch) return 0;
    $userPrompt = trim((string)($batch['input_text'] ?? ''));
    $imageDesc = trim((string)($batch['image_desc'] ?? ''));
    if ($userPrompt === '' && $imageDesc === '') return 0;

    $q = $pdo->prepare("SELECT a.id, a.name, a.kind, a.mix_of FROM artists a LEFT JOIN images i ON i.artist_id=a.id AND i.parent_id IS NULL WHERE a.batch_id=? AND i.id IS NULL");
    $q->execute([$batchId]);
    $pending = $q->fetchAll();
    if (!$pending) return 0;
    $ins = $pdo->prepare("INSERT INTO images (batch_id, artist_id, prompt, status) VALUES (?, ?, ?, 'pending')");
    $created = 0;
    foreach ($pending as $a) {
        $ins->execute([$batchId, (int)$a['id'], buildPromptForArtist($a, $userPrompt, $imageDesc)]);
        $created++;
    }
    return $created;
}

$action = $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');

    $readOnly = ['get_state','gallery','batch_status','list_batches','get_keys','batch_get'];
    if (!in_array($action, $readOnly, true) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['error' => 'POST requis'], 405);
    }

    try {
        switch ($action) {

            case 'get_state': {
                $pdo = db();
                $keys = (int)$pdo->query("SELECT COUNT(*) FROM api_keys")->fetchColumn();
                $batches = $pdo->query("
                    SELECT b.id, b.input_text, b.image_desc, b.created_at, b.target_famous, b.target_mix,
                      (SELECT COUNT(*) FROM images WHERE batch_id=b.id AND parent_id IS NULL) AS images_total,
                      (SELECT COUNT(*) FROM images WHERE batch_id=b.id AND status='done') AS images_done,
                      (SELECT COUNT(*) FROM images WHERE batch_id=b.id AND status='error') AS images_error
                    FROM batches b ORDER BY b.id DESC LIMIT 20")->fetchAll();
                respond(['ok' => true, 'keys_count' => $keys, 'batches' => $batches, 'current' => $batches[0] ?? null]);
            }

            case 'get_keys': {
                $rows = db()->query("SELECT \"key\", rpm FROM api_keys ORDER BY id")->fetchAll();
                respond(['ok' => true, 'keys' => $rows]);
            }

            case 'save_keys': {
                $b = body();
                $keys = is_array($b['keys'] ?? null) ? $b['keys'] : [];
                $clean = [];
                foreach ($keys as $k) {
                    if (!is_array($k)) continue;
                    $key = trim((string)($k['key'] ?? ''));
                    $rpm = max(1, min(500, (int)($k['rpm'] ?? 10)));
                    if (strlen($key) < 20 || strpos($key, 'sk-') !== 0) continue;
                    $clean[$key] = $rpm;
                }
                $pdo = db();
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    $pdo->exec("DELETE FROM api_keys");
                    $ins = $pdo->prepare("INSERT INTO api_keys (\"key\", rpm) VALUES (?, ?)");
                    foreach ($clean as $key => $rpm) $ins->execute([$key, $rpm]);
                    $pdo->exec('COMMIT');
                } catch (Throwable $e) {
                    try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {}
                    respond(['error' => $e->getMessage()], 500);
                }
                respond(['ok' => true, 'saved' => count($clean)]);
            }

            case 'test_keys': {
                $keys = array_slice(sanitizeKeys(body()['keys'] ?? []), 0, 10);
                if (!$keys) respond(['error' => 'Aucune clé'], 400);
                $results = [];
                foreach ($keys as $i => $key) {
                    $ch = curl_init(AGNES_BASE . '/chat/completions');
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode(['model' => AGNES_TEXT_MODEL, 'messages' => [['role'=>'user','content'=>'ok']], 'max_tokens' => 1]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6,
                        CURLOPT_SSL_VERIFYPEER => true,
                    ]);
                    curl_exec($ch);
                    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $results[$i] = ['index' => $i, 'ok' => ($code >= 200 && $code < 300), 'status' => $code];
                }
                respond(['ok' => true, 'results' => $results]);
            }

            case 'reset_all': {
                $pdo = db();
                $pdo->exec("DELETE FROM images");
                $pdo->exec("DELETE FROM artists");
                $pdo->exec("DELETE FROM batches");
                respond(['ok' => true]);
            }

            case 'delete_batch': {
                $id = (int)(body()['batch_id'] ?? 0);
                if ($id <= 0) respond(['error' => 'batch_id requis'], 400);
                $pdo = db();
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    $pdo->prepare("DELETE FROM images WHERE batch_id=?")->execute([$id]);
                    $pdo->prepare("DELETE FROM artists WHERE batch_id=?")->execute([$id]);
                    $pdo->prepare("DELETE FROM batches WHERE id=?")->execute([$id]);
                    $pdo->exec('COMMIT');
                } catch (Throwable $e) {
                    try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {}
                    respond(['error' => $e->getMessage()], 500);
                }
                respond(['ok' => true]);
            }

            case 'create_batch': {
                $b = body();
                $prompt = trim((string)($b['prompt'] ?? ''));
                $targetFamous = max(1, min(500, (int)($b['famous'] ?? 200)));
                $targetMix = max(0, min(500, (int)($b['mix'] ?? 200)));
                $ratio = (string)($b['ratio'] ?? '9:16');
                $size = (string)($b['size'] ?? '1K');
                if (!in_array($size, ['1K','2K','3K','4K'], true)) $size = '1K';
                if (!in_array($ratio, ['1:1','3:4','4:3','16:9','9:16','2:3','3:2','21:9'], true)) $ratio = '9:16';
                $pdo = db();
                $pdo->prepare("INSERT INTO batches (input_text, image_desc, target_famous, target_mix, ratio, size) VALUES (?, '', ?, ?, ?, ?)")
                    ->execute([$prompt, $targetFamous, $targetMix, $ratio, $size]);
                respond(['ok' => true, 'batch_id' => (int)$pdo->lastInsertId()]);
            }

            case 'analyze_image': {
                $b = body();
                $batchId = (int)($b['batch_id'] ?? 0);
                $image = (string)($b['image'] ?? '');
                $keys = sanitizeKeys($b['keys'] ?? []);
                if ($batchId <= 0 || $image === '' || !$keys) respond(['error' => 'batch_id, image, keys requis'], 400);
                try {
                    $sys = "You extract the CREATIVE ESSENCE of a reference image as a COMPACT brief for artistic reinterpretation.\n\n"
                         . "Output EXACTLY these 5 sections, plain text, no markdown, no JSON, no preamble:\n\n"
                         . "THEME: <1 short sentence — core subject and its narrative>\n"
                         . "COMPOSITION: <1 short sentence — framing, camera angle, focal point, layout>\n"
                         . "CHARACTERS: <compact comma-separated list — role + 2 key traits each; or \"none\">\n"
                         . "STYLE: <1 short sentence — mood and atmosphere only>\n"
                         . "TEXT: <ONLY if the text is THEMATICALLY ESSENTIAL to the artwork itself (e.g. a single word carrying the whole concept, a real poster title, a protest sign). Max 15 characters. In ALL other cases write \"none\".>\n\n"
                         . "RULES:\n"
                         . "- TOTAL under 100 words.\n"
                         . "- Do NOT describe clothing items, exact colors, textures or photographic specifics.\n"
                         . "- NEVER transcribe banners, watermarks, brand names, UI overlays, Instagram/TikTok captions, logos, prices, hashtags, or casual signage. The reference's text is usually NOT artistic — ignore it.\n"
                         . "- When in doubt about TEXT, output \"none\".\n"
                         . "- Focus only on what matters for creative reinterpretation, not literal reproduction.\n"
                         . "- Leave maximum creative space for artists to express their own vision.";
                    $v = agnesRun('/chat/completions', [
                        'messages' => [
                            ['role' => 'system', 'content' => $sys],
                            ['role' => 'user', 'content' => [
                                ['type' => 'text', 'text' => 'Extract the creative essence.'],
                                ['type' => 'image_url', 'image_url' => ['url' => $image]],
                            ]],
                        ],
                        'temperature' => 0.2,
                        'max_tokens' => 500,
                        'reasoning_effort' => 'none',
                        'chat_template_kwargs' => ['enable_thinking' => false],
                    ], $keys, 130);
                    $desc = trim((string)($v['choices'][0]['message']['content'] ?? ''));
                    if ($desc === '') throw new RuntimeException('Réponse vide');
                    db()->prepare("UPDATE batches SET image_desc = ? WHERE id = ?")->execute([$desc, $batchId]);
                    $pdo = db();
                    $pdo->exec('BEGIN IMMEDIATE');
                    try { $mat = materializePending($pdo, $batchId); $pdo->exec('COMMIT'); }
                    catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {} $mat = 0; }
                    respond(['ok' => true, 'description' => $desc, 'materialized' => $mat, 'essence' => parseEssence($desc)]);
                } catch (Throwable $e) {
                    respond(['error' => 'Analyse : ' . $e->getMessage(), 'retryable' => true], 500);
                }
            }

            case 'expand_artists': {
                $b = body();
                $batchId = (int)($b['batch_id'] ?? 0);
                $want = max(1, min(500, (int)($b['want'] ?? 80)));
                $target = max(1, min(500, (int)($b['target'] ?? 200)));
                $keys = sanitizeKeys($b['keys'] ?? []);
                if ($batchId <= 0 || !$keys) respond(['error' => 'batch_id, keys requis'], 400);

                $pdo = db();
                $bst = $pdo->prepare("SELECT input_text, image_desc FROM batches WHERE id = ?");
                $bst->execute([$batchId]);
                $batch = $bst->fetch();
                if (!$batch) respond(['error' => 'Batch introuvable'], 404);

                $userPrompt = trim((string)($batch['input_text'] ?? ''));
                $imageDesc = trim((string)($batch['image_desc'] ?? ''));
                $subject = $userPrompt;
                if ($subject === '' && $imageDesc !== '') {
                    $e = parseEssence($imageDesc);
                    $subject = trim(($e['theme'] ?? '') . "\n" . ($e['composition'] ?? '') . "\n" . ($e['characters'] ?? ''));
                }
                if ($subject === '') respond(['error' => 'Sujet vide — analyse image requise'], 400);

                $existing = $pdo->prepare("SELECT name FROM artists WHERE batch_id = ? AND kind = 'famous'");
                $existing->execute([$batchId]);
                $existingNames = array_column($existing->fetchAll(), 'name');
                $current = count($existingNames);

                if ($current >= $target) {
                    $mat = 0;
                    $pdo->exec('BEGIN IMMEDIATE');
                    try { $mat = materializePending($pdo, $batchId); $pdo->exec('COMMIT'); }
                    catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {} }
                    respond(['ok' => true, 'done' => true, 'total' => $current, 'added' => 0, 'materialized' => $mat]);
                }

                $need = min($want, $target - $current + 10);
                $sys = "Output a plain newline-separated list of real famous visual artists. No numbering, no JSON, no markdown — one name per line only.";
                $usr = "SUBJECT:\n" . $subject . "\n\nReturn exactly " . $need . " distinct famous artists, one per line.";
                if (!empty($existingNames)) $usr .= "\n\nEXCLUDE:\n" . implode("\n", array_slice($existingNames, -150));

                try {
                    $g = agnesRun('/chat/completions', [
                        'messages' => [
                            ['role' => 'system', 'content' => $sys],
                            ['role' => 'user', 'content' => $usr],
                        ],
                        'temperature' => 0.7,
                        'max_tokens' => min(2400, $need * 12 + 200),
                        'reasoning_effort' => 'none',
                        'chat_template_kwargs' => ['enable_thinking' => false],
                    ], $keys, 90);
                    $raw = (string)($g['choices'][0]['message']['content'] ?? '');
                } catch (Throwable $e) {
                    respond(['error' => 'Génération : ' . $e->getMessage(), 'retryable' => true, 'total' => $current], 500);
                }

                $lines = preg_split('/[\r\n]+/', $raw);
                $names = [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    $line = preg_replace('/^[\-\*•\d\.\)\]:]+\s*/u', '', $line);
                    $line = trim($line, " \t\"'`.,;:");
                    $line = preg_replace('/\s+/', ' ', $line);
                    if ($line === '' || mb_strlen($line) < 3 || mb_strlen($line) > 100) continue;
                    if (preg_match('/^(artist|names?|here|list|sure|voici)/i', $line)) continue;
                    if (substr_count($line, ' ') > 6) continue;
                    $names[] = $line;
                }
                if (empty($names)) respond(['error' => 'Aucun nom', 'retryable' => true, 'total' => $current], 500);

                $existingLower = array_map('mb_strtolower', $existingNames);
                $inserted = 0;
                $ins = $pdo->prepare("INSERT OR IGNORE INTO artists (batch_id, name, kind) VALUES (?, ?, 'famous')");
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    foreach ($names as $n) {
                        $ln = mb_strtolower($n);
                        if (in_array($ln, $existingLower, true)) continue;
                        $ins->execute([$batchId, $n]);
                        if ($ins->rowCount() > 0) { $inserted++; $existingLower[] = $ln; }
                        if ($inserted >= $need) break;
                    }
                    $mat = materializePending($pdo, $batchId);
                    $pdo->exec('COMMIT');
                } catch (Throwable $e) {
                    try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {}
                    respond(['error' => $e->getMessage()], 500);
                }
                $newTotal = $current + $inserted;
                respond(['ok' => true, 'added' => $inserted, 'total' => $newTotal, 'done' => $newTotal >= $target, 'materialized' => $mat]);
            }

            case 'build_mixes': {
                $b = body();
                $batchId = (int)($b['batch_id'] ?? 0);
                $count = max(0, min(500, (int)($b['count'] ?? 200)));
                if ($batchId <= 0) respond(['error' => 'batch_id requis'], 400);
                if ($count === 0) respond(['ok' => true, 'added' => 0, 'total' => 0, 'done' => true, 'materialized' => 0]);

                $pdo = db();
                $famous = $pdo->prepare("SELECT name FROM artists WHERE batch_id = ? AND kind = 'famous'");
                $famous->execute([$batchId]);
                $names = array_column($famous->fetchAll(), 'name');
                if (count($names) < 3) respond(['error' => 'Pas assez d\'artistes (min 3)'], 400);

                $existing = $pdo->prepare("SELECT COUNT(*) FROM artists WHERE batch_id = ? AND kind = 'mix'");
                $existing->execute([$batchId]);
                $have = (int)$existing->fetchColumn();

                $need = max(0, $count - $have);
                $inserted = 0;
                $mat = 0;
                if ($need > 0) {
                    $ins = $pdo->prepare("INSERT OR IGNORE INTO artists (batch_id, name, kind, mix_of) VALUES (?, ?, 'mix', ?)");
                    $pdo->exec('BEGIN IMMEDIATE');
                    try {
                        $tries = 0;
                        $maxTries = $need * 30 + 500;
                        while ($inserted < $need && $tries < $maxTries) {
                            $tries++;
                            $idx = array_rand($names, 3);
                            if (!is_array($idx)) continue;
                            $pick = [$names[$idx[0]], $names[$idx[1]], $names[$idx[2]]];
                            sort($pick);
                            $mixName = 'Mix: ' . implode(' × ', $pick);
                            if (mb_strlen($mixName) > 250) continue;
                            $ins->execute([$batchId, $mixName, json_encode($pick, JSON_UNESCAPED_UNICODE)]);
                            if ($ins->rowCount() > 0) $inserted++;
                        }
                        $mat = materializePending($pdo, $batchId);
                        $pdo->exec('COMMIT');
                    } catch (Throwable $e) {
                        try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {}
                        respond(['error' => $e->getMessage()], 500);
                    }
                }
                respond(['ok' => true, 'added' => $inserted, 'total' => $have + $inserted, 'materialized' => $mat]);
            }

            case 'materialize_images': {
                $b = body();
                $batchId = (int)($b['batch_id'] ?? 0);
                if ($batchId <= 0) respond(['error' => 'batch_id requis'], 400);
                $pdo = db();
                $pdo->exec('BEGIN IMMEDIATE');
                try { $created = materializePending($pdo, $batchId); $pdo->exec('COMMIT'); }
                catch (Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {} respond(['error' => $e->getMessage()], 500); }
                $c = $pdo->prepare("SELECT COUNT(*) FROM images WHERE batch_id = ? AND parent_id IS NULL");
                $c->execute([$batchId]);
                respond(['ok' => true, 'created' => $created, 'total' => (int)$c->fetchColumn()]);
            }

            case 'process_one': {
                $b = body();
                $batchId = (int)($b['batch_id'] ?? 0);
                $keys = sanitizeKeys($b['keys'] ?? []);
                $count = max(1, min(8, (int)($b['count'] ?? 4)));
                $userId = max(1, (int)($b['user_id'] ?? $sessionUserId));
                if ($batchId <= 0 || !$keys) respond(['error' => 'batch_id, keys requis'], 400);

                $pdo = db();
                $pdo->exec('BEGIN IMMEDIATE');
                try {
                    $pdo->prepare("UPDATE images SET status='pending', updated_at=datetime('now') WHERE batch_id=? AND status='running' AND updated_at < datetime('now','-5 minutes')")->execute([$batchId]);
                    $st = $pdo->prepare("SELECT i.*, a.name AS artist_name, a.kind, b.ratio, b.size FROM images i JOIN artists a ON a.id=i.artist_id JOIN batches b ON b.id=i.batch_id WHERE i.batch_id=? AND i.status='pending' ORDER BY i.id LIMIT ?");
                    $st->execute([$batchId, $count]);
                    $rows = $st->fetchAll();
                    if (!$rows) {
                        $pendingImgs = (int)$pdo->query("SELECT COUNT(*) FROM images WHERE batch_id=$batchId AND status IN ('pending','running')")->fetchColumn();
                        $pendingArt = (int)$pdo->query("SELECT COUNT(*) FROM artists a WHERE a.batch_id=$batchId AND NOT EXISTS (SELECT 1 FROM images i WHERE i.artist_id=a.id AND i.parent_id IS NULL)")->fetchColumn();
                        $busy = $pendingImgs + $pendingArt;
                        $pdo->exec('COMMIT');
                        respond(['ok' => true, 'done' => true, 'busy' => $busy]);
                    }
                    $upd = $pdo->prepare("UPDATE images SET status='running', updated_at=datetime('now') WHERE id=?");
                    foreach ($rows as $r) $upd->execute([$r['id']]);
                    $pdo->exec('COMMIT');
                } catch (Throwable $e) {
                    try { $pdo->exec('ROLLBACK'); } catch (Throwable $x) {}
                    respond(['error' => 'claim: ' . $e->getMessage()], 500);
                }

                shuffle($keys);
                $mh = curl_multi_init();
                $handles = [];
                $i = 0;
                foreach ($rows as $row) {
                    $size = in_array($row['size'], ['1K','2K','3K','4K'], true) ? $row['size'] : '1K';
                    $ratio = in_array($row['ratio'], ['1:1','3:4','4:3','16:9','9:16','2:3','3:2','21:9'], true) ? $row['ratio'] : '9:16';
                    $key = $keys[$i % count($keys)];
                    $i++;
                    $payload = [
                        'model' => AGNES_IMG_MODEL,
                        'prompt' => mb_substr($row['prompt'], 0, 5000),
                        'size' => $size,
                        'ratio' => $ratio,
                        'extra_body' => ['response_format' => 'url'],
                    ];
                    $ch = curl_init(AGNES_BASE . '/images/generations');
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT => 170,
                        CURLOPT_CONNECTTIMEOUT => 15,
                        CURLOPT_SSL_VERIFYPEER => true,
                    ]);
                    $handles[] = ['ch' => $ch, 'row' => $row];
                    curl_multi_add_handle($mh, $ch);
                }

                $running = null;
                do {
                    $status = curl_multi_exec($mh, $running);
                    if ($running) curl_multi_select($mh, 0.5);
                } while ($running > 0 && $status === CURLM_OK);

                $results = [];
                foreach ($handles as $h) {
                    $ch = $h['ch'];
                    $row = $h['row'];
                    $content = curl_multi_getcontent($ch);
                    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno = curl_errno($ch);
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    try {
                        if ($errno === 28) throw new RuntimeException('Timeout');
                        if ($errno) throw new RuntimeException('Curl err ' . $errno);
                        $data = json_decode($content, true);
                        if ($httpCode >= 400) {
                            $msg = $data['error']['message'] ?? substr($content, 0, 200);
                            throw new RuntimeException('HTTP ' . $httpCode . ': ' . $msg);
                        }
                        $url = $data['data'][0]['url'] ?? null;
                        if (!$url && isset($data['data'][0]['b64_json'])) $url = 'data:image/png;base64,' . $data['data'][0]['b64_json'];
                        if (!$url) throw new RuntimeException('Pas d\'URL');
                        [$full, $thumb] = downloadImage($url, $userId, 'img' . $row['id'] . '_a' . $row['artist_id']);
                        $pdo->prepare("UPDATE images SET status='done', image_url=?, thumb_url=?, error=NULL, updated_at=datetime('now') WHERE id=?")
                            ->execute([$full, $thumb ?: $full, $row['id']]);
                        $results[] = ['ok' => true, 'id' => (int)$row['id'], 'url' => $full, 'thumb' => $thumb ?: $full, 'artist' => $row['artist_name']];
                    } catch (Throwable $e) {
                        $msg = substr($e->getMessage(), 0, 300);
                        $pdo->prepare("UPDATE images SET error=?, status=CASE WHEN attempts+1 < 3 THEN 'pending' ELSE 'error' END, attempts=attempts+1, updated_at=datetime('now') WHERE id=?")
                            ->execute([$msg, $row['id']]);
                        $results[] = ['ok' => false, 'id' => (int)$row['id'], 'error' => $msg];
                    }
                }
                curl_multi_close($mh);
                respond(['ok' => true, 'results' => $results]);
            }

            case 'save_prompt': {
                $b = body();
                $id = (int)($b['id'] ?? 0);
                $prompt = trim((string)($b['prompt'] ?? ''));
                if ($id <= 0 || $prompt === '') respond(['error' => 'id, prompt requis'], 400);
                $up = db()->prepare("UPDATE images SET prompt=?, updated_at=datetime('now') WHERE id=? AND status IN ('pending','error')");
                $up->execute([$prompt, $id]);
                respond(['ok' => true, 'affected' => $up->rowCount()]);
            }

            case 'regenerate': {
                $b = body();
                $parentId = (int)($b['image_id'] ?? 0);
                $newPrompt = trim((string)($b['prompt'] ?? ''));
                if ($parentId <= 0 || $newPrompt === '') respond(['error' => 'image_id, prompt requis'], 400);
                $pdo = db();
                $st = $pdo->prepare("SELECT * FROM images WHERE id=?");
                $st->execute([$parentId]);
                $p = $st->fetch();
                if (!$p) respond(['error' => 'Introuvable'], 404);
                $rootId = $p['parent_id'] ? (int)$p['parent_id'] : (int)$p['id'];
                $pdo->prepare("INSERT INTO images (batch_id, artist_id, prompt, parent_id, status) VALUES (?, ?, ?, ?, 'pending')")
                    ->execute([(int)$p['batch_id'], (int)$p['artist_id'], $newPrompt, $rootId]);
                respond(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'parent_id' => $rootId]);
            }

            case 'retry_image': {
                $id = (int)(body()['id'] ?? 0);
                if ($id <= 0) respond(['error' => 'id requis'], 400);
                $up = db()->prepare("UPDATE images SET status='pending', error=NULL, attempts=0, updated_at=datetime('now') WHERE id=? AND status='error'");
                $up->execute([$id]);
                respond(['ok' => true, 'requeued' => $up->rowCount() > 0]);
            }

            case 'gallery': {
                $batchId = (int)($_GET['batch_id'] ?? 0);
                $page = max(1, (int)($_GET['page'] ?? 1));
                $per = max(1, min(50, (int)($_GET['per_page'] ?? 12)));
                $filter = (string)($_GET['filter'] ?? 'all');
                $off = ($page - 1) * $per;
                if ($batchId <= 0) respond(['error' => 'batch_id requis'], 400);
                $pdo = db();

                $where = "batch_id=? AND parent_id IS NULL";
                $args = [$batchId];
                if ($filter === 'famous') $where .= " AND artist_id IN (SELECT id FROM artists WHERE kind='famous')";
                if ($filter === 'mix') $where .= " AND artist_id IN (SELECT id FROM artists WHERE kind='mix')";
                if ($filter === 'done') $where .= " AND status='done'";
                if ($filter === 'error') $where .= " AND status='error'";

                $c = $pdo->prepare("SELECT COUNT(*) FROM images WHERE $where");
                $c->execute($args);
                $total = (int)$c->fetchColumn();
                $pages = max(1, (int)ceil($total / $per));

                $st = $pdo->prepare("SELECT i.id, i.artist_id, i.prompt, i.image_url, i.thumb_url, i.status, i.error, i.updated_at, a.name AS artist_name, a.kind AS artist_kind, a.mix_of FROM images i JOIN artists a ON a.id=i.artist_id WHERE i.$where ORDER BY i.id LIMIT ? OFFSET ?");
                $st->execute(array_merge($args, [$per, $off]));
                $items = $st->fetchAll();

                if ($items) {
                    $ids = array_column($items, 'id');
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $ch = $pdo->prepare("SELECT id, artist_id, prompt, image_url, thumb_url, status, error, parent_id, updated_at FROM images WHERE parent_id IN ($ph) ORDER BY id");
                    $ch->execute($ids);
                    $byParent = [];
                    foreach ($ch->fetchAll() as $cv) $byParent[$cv['parent_id']][] = $cv;
                    foreach ($items as &$it) $it['versions'] = $byParent[$it['id']] ?? [];
                    unset($it);
                }

                $cnt = $pdo->prepare("SELECT status, COUNT(*) c FROM images WHERE batch_id=? GROUP BY status");
                $cnt->execute([$batchId]);
                $counts = ['pending'=>0,'running'=>0,'done'=>0,'error'=>0,'total'=>0];
                foreach ($cnt->fetchAll() as $r) { $counts[$r['status']] = (int)$r['c']; $counts['total'] += (int)$r['c']; }

                respond(['ok' => true, 'items' => $items, 'total' => $total, 'pages' => $pages, 'page' => $page, 'per_page' => $per, 'counts' => $counts, 'now' => time()]);
            }

            case 'batch_status': {
                $batchId = (int)($_GET['batch_id'] ?? 0);
                if ($batchId <= 0) respond(['error' => 'batch_id requis'], 400);
                $cnt = db()->prepare("SELECT status, COUNT(*) c FROM images WHERE batch_id=? GROUP BY status");
                $cnt->execute([$batchId]);
                $counts = ['pending'=>0,'running'=>0,'done'=>0,'error'=>0,'total'=>0];
                foreach ($cnt->fetchAll() as $r) { $counts[$r['status']] = (int)$r['c']; $counts['total'] += (int)$r['c']; }
                respond(['ok' => true, 'counts' => $counts, 'now' => time()]);
            }

            case 'list_batches': {
                $rows = db()->query("
                    SELECT b.*,
                      (SELECT COUNT(*) FROM images WHERE batch_id=b.id AND parent_id IS NULL) AS images_total,
                      (SELECT COUNT(*) FROM images WHERE batch_id=b.id AND status='done') AS images_done,
                      (SELECT COUNT(*) FROM images WHERE batch_id=b.id AND status='error') AS images_error
                    FROM batches b ORDER BY b.id DESC LIMIT 30")->fetchAll();
                respond(['ok' => true, 'batches' => $rows]);
            }

            default:
                respond(['error' => 'Action inconnue'], 404);
        }
    } catch (Throwable $e) {
        error_log('[ArtApp] ' . $e->getMessage());
        respond(['error' => $e->getMessage(), 'retryable' => true], 500);
    }
    exit;
}

header('Content-Type: text/html; charset=utf-8');
$ajaxUrl = ($basePath ?: '.') . '/' . basename(__FILE__);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Art Studio</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#fff;--bg2:#f6f7f9;--bg3:#eceff3;--line:#d8dde4;--line2:#b9c1cc;--ink:#101318;--ink2:#4b5460;--ink3:#7a8494;--violet:#5a2a8a;--violet-dim:rgba(90,42,138,.09);--green:#1e7a52;--green-dim:rgba(30,122,82,.10);--red:#b02a34;--red-dim:rgba(176,42,52,.09);--gold:#9a6b00;--gold-dim:rgba(154,107,0,.09);--cyan:#0e7a80;--cyan-dim:rgba(14,122,128,.09)}
body{font:14px/1.55 system-ui,sans-serif;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased}
#topbar{position:sticky;top:0;min-height:48px;padding:.5rem 1.1rem;background:rgba(255,255,255,.97);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);z-index:50;display:flex;align-items:center;gap:.7rem;font:12px ui-monospace,monospace}
#modeTag{padding:.2rem .6rem;border-radius:100px;border:1px solid var(--line);color:var(--ink3);font-weight:700;font-size:.66rem}
#modeTag.live{border-color:var(--green);color:var(--green)}
#globalStat{color:var(--ink2);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.72rem}
#main{max-width:1300px;margin:0 auto;padding:1rem 1rem 100px}
h1{font-family:Georgia,serif;font-size:1.6rem;color:var(--violet);font-weight:400;margin-bottom:.2rem}
.sub{color:var(--ink2);font-size:.85rem;margin-bottom:.9rem}
.card{background:var(--bg2);border:1px solid var(--line);border-radius:12px;padding:1rem;margin-bottom:.9rem}
label.lab{display:block;font-size:.66rem;color:var(--ink2);text-transform:uppercase;letter-spacing:.1em;font-weight:700;margin:.6rem 0 .3rem}
input[type=text],input[type=number],textarea,select{width:100%;background:#fff;border:1px solid var(--line2);border-radius:8px;padding:.55rem .8rem;font:13px system-ui;color:var(--ink);outline:none;min-height:38px}
textarea{min-height:80px;line-height:1.55;resize:vertical;font-family:inherit}
input:focus,textarea:focus,select:focus{border-color:var(--violet);box-shadow:0 0 0 3px rgba(90,42,138,.12)}
.row{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}
.row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem}
@media(max-width:700px){.row,.row3{grid-template-columns:1fr}}
.btn{min-height:38px;padding:.5rem 1rem;border-radius:8px;font:700 .8rem system-ui;cursor:pointer;border:1px solid transparent;display:inline-flex;align-items:center;justify-content:center;gap:.35rem;background:var(--bg3);color:var(--ink2)}
.btn:hover{background:var(--violet-dim);color:var(--violet)}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn-mega{min-height:50px;background:var(--violet);color:#fff;border:none;font-size:.95rem;font-weight:800;width:100%;border-radius:10px;cursor:pointer;margin-top:.9rem}
.btn-mega:disabled{opacity:.55;cursor:not-allowed}
.btn-red{background:var(--red-dim);border-color:var(--red);color:var(--red)}
.btn-green{background:var(--green-dim);border-color:var(--green);color:var(--green)}
.btn-slate{background:#fff;border-color:var(--line);color:var(--ink2);font-size:.72rem}
.btn-mini{background:#fff;border:1px solid var(--line);color:var(--ink2);font-size:.68rem;padding:.28rem .6rem;border-radius:5px;cursor:pointer;font-family:inherit;min-height:0;white-space:nowrap}
.btn-mini:hover{color:var(--violet);border-color:var(--violet)}
.drop{border:2px dashed #b9c1cc;border-radius:11px;padding:1rem;text-align:center;cursor:pointer;position:relative}
.drop:hover{border-color:var(--violet);background:var(--violet-dim)}
.drop input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.preview{margin-top:.6rem;position:relative;display:inline-block;max-width:180px}
.preview img{max-width:100%;border-radius:8px;border:1px solid var(--line);display:block}
.preview .rm{position:absolute;top:4px;right:4px;background:rgba(176,42,52,.95);color:#fff;border:none;border-radius:50%;width:22px;height:22px;cursor:pointer;font-size:.7rem}
.status{font:12px/1.5 system-ui;padding:.55rem .8rem;border-radius:8px;background:var(--bg3);color:var(--ink2);border-left:3px solid var(--line);word-break:break-word;margin-top:.4rem}
.status.ok{background:var(--green-dim);color:var(--green);border-left-color:var(--green)}
.status.err{background:var(--red-dim);color:var(--red);border-left-color:var(--red)}
.status.warn{background:var(--gold-dim);color:var(--gold);border-left-color:var(--gold)}
.spinner{display:inline-block;width:11px;height:11px;border:2px solid rgba(90,42,138,.22);border-top-color:var(--violet);border-radius:50%;animation:spin .8s linear infinite;vertical-align:middle}
@keyframes spin{to{transform:rotate(360deg)}}
.progress-row{display:flex;align-items:center;gap:.7rem;font-size:.74rem;color:var(--ink2);margin:.25rem 0}
.progress-row .k{font:700 .64rem ui-monospace;color:var(--ink3);text-transform:uppercase;min-width:62px}
.progress-row .v{font-weight:700;color:var(--ink);min-width:70px;text-align:right}
.progress-row .bar{flex:1;height:8px;background:var(--bg3);border-radius:4px;overflow:hidden}
.progress-row .bar .fill{height:100%;background:var(--green);transition:width .4s;width:0}
.steps{margin-top:.6rem;font-size:.78rem}
.step{display:flex;align-items:center;gap:.5rem;padding:.3rem 0;color:var(--ink3)}
.step.active{color:var(--violet);font-weight:600}
.step.done{color:var(--green)}
.step.error{color:var(--red)}
.step .dot{width:18px;height:18px;border-radius:50%;border:2px solid currentColor;display:inline-flex;align-items:center;justify-content:center;font-size:.6rem;flex-shrink:0}
.step.done .dot{background:var(--green);color:#fff;border-color:var(--green)}
.step.error .dot{background:var(--red);color:#fff;border-color:var(--red)}
.step.active .dot{background:var(--violet);color:#fff;border-color:var(--violet)}
.step .time{margin-left:auto;font:600 .7rem ui-monospace;color:var(--ink3)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:.8rem;margin-top:.8rem}
.img-cell{background:#fff;border:1px solid var(--line);border-radius:12px;padding:.6rem;content-visibility:auto;contain-intrinsic-size:auto 500px;display:flex;flex-direction:column;gap:.4rem}
.ic-head{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.ic-name{font-weight:700;font-size:.9rem;color:var(--violet);font-family:Georgia,serif;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ic-cat{font:700 .58rem ui-monospace;padding:.15rem .45rem;border-radius:4px;text-transform:uppercase;background:var(--bg3);color:var(--ink2)}
.ic-cat.famous{background:var(--violet-dim);color:var(--violet)}
.ic-cat.mix{background:var(--cyan-dim);color:var(--cyan)}
.ic-img{aspect-ratio:9/16;background:var(--bg3);border-radius:8px;overflow:hidden;position:relative;display:flex;align-items:center;justify-content:center}
.ic-img img{width:100%;height:100%;object-fit:cover;display:block;cursor:zoom-in;opacity:0;transition:opacity .3s}
.ic-img img.loaded{opacity:1}
.ic-img .ph{color:var(--ink3);font-size:.68rem;text-align:center;padding:.7rem;line-height:1.4}
.ic-img .ph.err{color:var(--red)}
.ic-img .ph.shimmer{width:100%;height:100%;display:flex;align-items:center;justify-content:center;gap:.4rem;background:linear-gradient(110deg,var(--bg3) 30%,#fff 50%,var(--bg3) 70%);background-size:200% 100%;animation:shim 1.4s linear infinite}
@keyframes shim{to{background-position:-200% 0}}
.ic-prompt-wrap{font-size:.7rem}
.ic-prompt-wrap summary{cursor:pointer;color:var(--violet);font-weight:700;padding:.2rem 0;user-select:none}
.ic-prompt{min-height:60px;font-size:11px;line-height:1.4;font-family:ui-monospace;margin-top:.3rem}
.ic-actions{display:flex;gap:.3rem;margin-top:.3rem;flex-wrap:wrap}
.versions{display:flex;flex-direction:column;gap:.4rem;margin-top:.3rem}
.version{border:1px solid var(--line);border-radius:8px;padding:.4rem;background:var(--bg2)}
.v-img{aspect-ratio:9/16;background:var(--bg3);border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center;margin-bottom:.3rem}
.v-img img{width:100%;height:100%;object-fit:cover;cursor:zoom-in}
.v-img .ph{font-size:.65rem;color:var(--ink3);padding:.4rem;text-align:center}
.v-img .ph.err{color:var(--red)}
.v-header{display:flex;justify-content:space-between;align-items:center;font-size:.65rem;color:var(--ink3);margin-bottom:.2rem}
.pager{display:flex;align-items:center;justify-content:center;gap:.3rem;padding:1rem 0;flex-wrap:wrap}
.pager .pg{min-width:34px;height:34px;padding:0 .55rem;border-radius:6px;border:1px solid var(--line);background:#fff;color:var(--ink2);cursor:pointer;font:600 .78rem system-ui}
.pager .pg:hover:not(:disabled){background:var(--violet-dim);color:var(--violet);border-color:var(--violet)}
.pager .pg.on{background:var(--violet);color:#fff;border-color:var(--violet)}
.pager .pg:disabled{opacity:.35;cursor:not-allowed}
.pager .dots{color:var(--ink3);padding:0 .2rem}
.pager .info{font-size:.74rem;color:var(--ink3);margin:0 .5rem}
#lightbox{position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:300;display:none;align-items:center;justify-content:center;cursor:zoom-out}
#lightbox.on{display:flex}
#lightbox img{max-height:94vh;max-width:94vw;border-radius:6px}
#lightbox a{position:absolute;top:14px;right:18px;color:#fff;font-size:.8rem;background:rgba(255,255,255,.15);padding:.35rem .7rem;border-radius:6px;text-decoration:none}
#apiPanel{position:fixed;bottom:70px;right:14px;width:min(560px,96vw);background:#fff;border:1px solid var(--line2);border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.18);z-index:60;display:none;max-height:70vh;overflow-y:auto;padding:.9rem 1.1rem}
#apiPanel.show{display:block}
#apiKeys{min-height:180px;font-family:ui-monospace;font-size:12px;line-height:1.5}
#openApiBtn{position:fixed;bottom:14px;right:14px;background:var(--violet);color:#fff;border:none;border-radius:50%;width:48px;height:48px;cursor:pointer;font-size:1.15rem;box-shadow:0 6px 20px rgba(90,42,138,.4);z-index:61}
#toast{position:fixed;top:56px;left:50%;transform:translateX(-50%) translateY(-140px);background:#fff;border:1px solid var(--line2);border-left:3px solid var(--violet);border-radius:10px;padding:.65rem 1rem;font-size:.78rem;z-index:200;opacity:0;transition:.3s;max-width:min(520px,92vw);text-align:center;box-shadow:0 8px 24px rgba(0,0,0,.12)}
#toast.on{transform:translateX(-50%) translateY(0);opacity:1}
#toast.ok{border-left-color:var(--green)}#toast.err{border-left-color:var(--red)}#toast.warn{border-left-color:var(--gold)}
.hidden{display:none!important}
.batch-row{display:flex;gap:.6rem;padding:.55rem .7rem;border:1px solid var(--line);border-radius:8px;margin-bottom:.4rem;background:#fff;align-items:center;flex-wrap:wrap;cursor:pointer}
.batch-row:hover{border-color:var(--violet)}
.batch-row.active{border-color:var(--violet);background:var(--violet-dim)}
.batch-row .bt{flex:1;font-weight:700;font-size:.85rem;min-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.batch-row .bs{font-size:.68rem;color:var(--ink3)}
.analysis-box{background:var(--bg3);border-radius:8px;padding:.6rem .8rem;font-size:.75rem;line-height:1.5;color:var(--ink2);margin-top:.5rem;max-height:180px;overflow-y:auto;white-space:pre-wrap;font-family:ui-monospace}
.opt-row{display:flex;gap:.6rem;align-items:center;font-size:.78rem;color:var(--ink2);margin:.5rem 0;flex-wrap:wrap}
.opt-row label{display:flex;gap:.35rem;align-items:center;cursor:pointer}
.opt-row input[type=checkbox]{width:auto;min-height:0}
</style>
</head>
<body>

<div id="topbar">
  <span id="modeTag">NON CONFIGURÉ</span>
  <span id="globalStat">Ajoutez vos clés API (🔑)</span>
</div>

<div id="main">
  <h1>🎨 Art Studio</h1>
  <p class="sub">1 prompt OU 1 image → essence extraite → 200 artistes + 200 mixes → œuvres originales comme de vrais tableaux</p>

  <div class="card">
    <label class="lab">📝 Prompt / description (optionnel)</label>
    <textarea id="prompt" placeholder="Ex: Une femme rousse en manteau noir, regard perçant, assise dans un café parisien sous la pluie…"></textarea>

    <label class="lab">📷 Image de référence (optionnel)</label>
    <div class="drop" id="drop">
      <input type="file" id="imgInput" accept="image/*">
      <div style="font-size:1.4rem;opacity:.55">📷</div>
      <div style="font-size:.78rem;color:var(--ink2)">Cliquez ou glissez une image</div>
    </div>
    <div id="previewWrap"></div>

    <div class="opt-row">
      <label><input type="checkbox" id="skipAnalysis" checked> ⚡ Si prompt fourni, ignorer l'analyse d'image (obligatoire sans prompt)</label>
    </div>

    <div class="row3" style="margin-top:.6rem">
      <div><label class="lab">Artistes célèbres</label><input type="number" id="numFamous" value="200" min="1" max="500"></div>
      <div><label class="lab">Mixes</label><input type="number" id="numMix" value="200" min="0" max="500"></div>
      <div><label class="lab">Workers (× 4 images)</label>
        <select id="workers"><option>4</option><option>6</option><option>8</option><option selected>10</option><option>12</option><option>16</option></select></div>
    </div>
    <div class="row">
      <div><label class="lab">Ratio</label>
        <select id="ratio">
          <option value="9:16" selected>9:16 portrait</option>
          <option value="3:4">3:4</option>
          <option value="2:3">2:3</option>
          <option value="1:1">1:1</option>
          <option value="16:9">16:9</option>
        </select></div>
      <div><label class="lab">Taille</label>
        <select id="size">
          <option value="1K" selected>1K (rapide)</option>
          <option value="2K">2K (qualité)</option>
        </select></div>
    </div>

    <button class="btn-mega" id="startBtn">🚀 Lancer la génération</button>
    <div id="startStatus" class="status">Prêt. Configurez vos clés (🔑 en bas à droite).</div>

    <div class="steps" id="steps">
      <div class="step" id="step-create"><span class="dot">1</span> Création du batch<span class="time" id="time-create"></span></div>
      <div class="step" id="step-analyze"><span class="dot">2</span> Extraction de l'essence<span class="time" id="time-analyze"></span></div>
      <div class="step" id="step-artists"><span class="dot">3</span> Recherche artistes<span class="time" id="time-artists"></span></div>
      <div class="step" id="step-mixes"><span class="dot">4</span> Génération mixes<span class="time" id="time-mixes"></span></div>
      <div class="step" id="step-prompt"><span class="dot">5</span> Prompts prêts<span class="time" id="time-prompt"></span></div>
    </div>

    <div id="analysisBox" class="analysis-box hidden"></div>

    <div style="display:flex;gap:.4rem;margin-top:.4rem;flex-wrap:wrap">
      <button class="btn btn-green hidden" id="pauseBtn">⏸ Pause</button>
      <button class="btn btn-slate hidden" id="resumeBtn">▶ Reprendre</button>
      <button class="btn btn-red hidden" id="stopBtn">⏹ Stop</button>
      <button class="btn btn-slate" id="resetBtn">🗑️ Reset tout</button>
    </div>
  </div>

  <div class="card">
    <div class="progress-row"><span class="k">Images</span><div class="bar"><div class="fill" id="barDone"></div></div><span class="v" id="statDone">0 / 0</span></div>
    <div class="progress-row"><span class="k">En cours</span><div class="bar"><div class="fill" id="barRun" style="background:var(--cyan)"></div></div><span class="v" id="statRun">0</span></div>
    <div class="progress-row"><span class="k">Erreurs</span><div class="bar"><div class="fill" id="barErr" style="background:var(--red)"></div></div><span class="v" id="statErr">0</span></div>
    <div id="etaLine" style="font-size:.72rem;color:var(--ink2);margin-top:.3rem"></div>
    <div id="batchInfo" style="font-size:.72rem;color:var(--ink3);margin-top:.3rem"></div>
  </div>

  <details class="card">
    <summary style="cursor:pointer;font-weight:700;color:var(--violet)">📚 Historique</summary>
    <div id="batchesList" style="margin-top:.6rem"></div>
  </details>

  <div style="display:flex;justify-content:space-between;align-items:center;margin:.5rem 0;flex-wrap:wrap;gap:.4rem">
    <h2 style="margin:0;font-size:1.05rem;color:var(--violet)">🖼️ Galerie</h2>
    <div style="display:flex;gap:.3rem">
      <select id="galFilter" style="width:auto">
        <option value="all">Tous</option>
        <option value="famous">Célèbres</option>
        <option value="mix">Mixes</option>
        <option value="done">Terminés</option>
        <option value="error">Erreurs</option>
      </select>
      <select id="perPage" style="width:auto">
        <option value="8">8 / page</option>
        <option value="12" selected>12 / page</option>
        <option value="20">20 / page</option>
        <option value="40">40 / page</option>
      </select>
    </div>
  </div>
  <div id="grid" class="grid"></div>
  <div id="empty" class="hidden" style="padding:2rem;text-align:center;color:var(--ink3);font-size:.85rem">Aucune image.</div>
  <div id="pager" class="pager"></div>
</div>

<div id="lightbox"><a id="lbDl" href="#" download target="_blank" rel="noopener">⬇ Télécharger</a><img alt=""></div>

<button id="openApiBtn" title="Clés API">🔑</button>
<div id="apiPanel">
  <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.4rem">
    <b style="color:var(--violet);font-size:.85rem">🔑 Clés API</b>
    <span id="apiSummary" style="font-size:.72rem;color:var(--ink2);flex:1"></span>
  </div>
  <p style="font-size:.72rem;color:var(--ink2);margin-bottom:.4rem">Une par ligne : <code>sk-xxxx 10</code> (RPM, défaut 10).</p>
  <textarea id="apiKeys" placeholder="sk-xxxxxxxx 10&#10;sk-yyyyyyyy 10&#10;sk-zzzzzzzz"></textarea>
  <div style="display:flex;gap:.35rem;margin-top:.5rem;flex-wrap:wrap">
    <button class="btn btn-slate" id="saveKeys">💾 Enregistrer</button>
    <button class="btn btn-slate" id="testKeys">✓ Tester</button>
    <button class="btn btn-slate" id="closeApiBtn">Fermer</button>
  </div>
  <div id="apiStatus" class="status">Aucune clé</div>
</div>

<div id="toast"></div>

<script>
window.APP = { ajaxUrl: <?= json_encode($ajaxUrl, JSON_UNESCAPED_SLASHES) ?>, userId: <?= json_encode($sessionUserId) ?> };
</script>
<script>
'use strict';
const $ = id => document.getElementById(id);
const esc = s => String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const sleep = ms => new Promise(r=>setTimeout(r,ms));

const CFG = { ajaxUrl: window.APP.ajaxUrl, userId: window.APP.userId };
const TIMEOUTS = { create_batch: 15000, analyze_image: 150000, expand_artists: 100000, build_mixes: 30000, materialize_images: 30000, process_one: 195000, test_keys: 20000 };
const IMG_PER_WORKER = 4;

let CURRENT_IMAGE = null;
let CURRENT_BATCH = 0;
let SETUP_RUNNING = false;

const RUN = { running: false, paused: false, stop: false, active: 0, t0: 0, done0: 0 };
const VIEW = { page: 1, per: 12, total: 0, pages: 1, counts: null, pollTimer: null, polling: false, items: [], filter: 'all' };
let API_KEYS = [];

async function api(action, params = {}, method = 'POST', timeout) {
  timeout = timeout || TIMEOUTS[action] || 25000;
  let url = CFG.ajaxUrl + '?action=' + encodeURIComponent(action);
  let opts;
  if (method === 'GET') {
    const qs = new URLSearchParams(params).toString();
    if (qs) url += '&' + qs;
    opts = { method: 'GET', credentials: 'same-origin' };
  } else {
    opts = { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(params), credentials: 'same-origin' };
  }
  const ctrl = new AbortController();
  const tid = setTimeout(()=>ctrl.abort(), timeout);
  let res;
  try { res = await fetch(url, {...opts, signal: ctrl.signal}); }
  catch (e) {
    clearTimeout(tid);
    const err = new Error(e.name === 'AbortError' ? 'Timeout ('+Math.round(timeout/1000)+'s)' : 'Réseau : '+e.message);
    err.retryable = true; throw err;
  }
  clearTimeout(tid);
  const txt = await res.text();
  let data;
  try { data = JSON.parse(txt); }
  catch (e) { const err = new Error('Réponse invalide (HTTP '+res.status+')'); err.retryable = true; err.status = res.status; throw err; }
  if (!res.ok) {
    const err = new Error(data.error || ('HTTP '+res.status));
    err.status = res.status;
    err.retryable = !!data.retryable || res.status >= 500 || res.status === 429;
    throw err;
  }
  return data;
}

function toast(msg, type='') {
  const t = $('toast');
  t.textContent = msg; t.className = 'on ' + type;
  clearTimeout(t._tid);
  t._tid = setTimeout(()=>t.className = type, 3200);
}

function parseKeysText(txt) {
  const out = [];
  for (const line of txt.split('\n')) {
    const parts = line.trim().split(/\s+/);
    if (!parts[0] || !parts[0].startsWith('sk-') || parts[0].length < 20) continue;
    out.push({ key: parts[0], rpm: Math.max(1, parseInt(parts[1],10)||10) });
  }
  return out;
}

async function loadKeysFromServer() {
  try {
    const r = await api('get_keys', {}, 'GET');
    API_KEYS = r.keys || [];
    $('apiKeys').value = API_KEYS.map(k => k.key + (k.rpm && k.rpm !== 10 ? ' ' + k.rpm : '')).join('\n');
    updateApiTag();
  } catch (e) { console.error(e); }
}

async function saveKeysToServer() {
  const entries = parseKeysText($('apiKeys').value);
  try {
    await api('save_keys', { keys: entries });
    API_KEYS = entries;
    updateApiTag();
    $('apiStatus').className = 'status ok';
    $('apiStatus').textContent = '✓ ' + entries.length + ' clé(s) enregistrée(s)';
  } catch (e) {
    $('apiStatus').className = 'status err';
    $('apiStatus').textContent = '❌ ' + e.message;
  }
}

function updateApiTag() {
  const n = API_KEYS.length;
  const tag = $('modeTag');
  if (n) {
    tag.textContent = 'LIVE · '+n; tag.className = 'live';
    const rpm = API_KEYS.reduce((s,e)=>s+e.rpm, 0);
    $('globalStat').innerHTML = '<b>'+n+'</b> clé(s) · '+rpm+' RPM cumulés';
    $('apiSummary').textContent = n+' clé(s) · '+rpm+' RPM';
  } else {
    tag.textContent = 'NON CONFIGURÉ'; tag.className = '';
    $('globalStat').textContent = 'Cliquez sur 🔑 pour ajouter vos clés';
    $('apiSummary').textContent = '0 clé';
  }
}

function compressImage(dataUrl, maxSide = 800) {
  return new Promise(res => {
    const img = new Image();
    img.onload = () => {
      let {width:w, height:h} = img;
      const s = Math.min(1, maxSide / Math.max(w, h));
      w = Math.round(w*s); h = Math.round(h*s);
      const c = document.createElement('canvas'); c.width = w; c.height = h;
      c.getContext('2d').drawImage(img, 0, 0, w, h);
      res(c.toDataURL('image/jpeg', 0.75));
    };
    img.onerror = () => res(dataUrl);
    img.src = dataUrl;
  });
}
async function handleImage(file) {
  if (!file || !file.type.startsWith('image/')) return;
  const dataUrl = await new Promise((res, rej) => { const r = new FileReader(); r.onload = e => res(e.target.result); r.onerror = rej; r.readAsDataURL(file); });
  CURRENT_IMAGE = await compressImage(dataUrl);
  renderPreview();
}
function renderPreview() {
  const w = $('previewWrap');
  if (!CURRENT_IMAGE) { w.innerHTML = ''; return; }
  w.innerHTML = '<div class="preview"><img src="'+CURRENT_IMAGE+'" alt=""><button class="rm" id="rmImg">✕</button></div>';
  $('rmImg').onclick = () => { CURRENT_IMAGE = null; renderPreview(); };
}

function openLightbox(url) { $('lightbox').querySelector('img').src = url; $('lbDl').href = url; $('lightbox').classList.add('on'); }
async function copyText(txt) { try { await navigator.clipboard.writeText(txt); toast('Copié', 'ok'); } catch (e) { toast('Erreur', 'err'); } }

function stepStart(name) {
  const el = $('step-' + name); if (!el) return;
  el.className = 'step active'; el._t0 = Date.now();
  const t = $('time-' + name); if (t) t.textContent = '';
  if (el._timer) clearInterval(el._timer);
  el._timer = setInterval(() => { const tt = $('time-'+name); if (tt) tt.textContent = ((Date.now()-el._t0)/1000).toFixed(1)+'s'; }, 200);
}
function stepDone(name, extra) {
  const el = $('step-' + name); if (!el) return;
  if (el._timer) { clearInterval(el._timer); el._timer = null; }
  el.className = 'step done'; el.querySelector('.dot').textContent = '✓';
  const t = $('time-' + name); if (t && el._t0) t.textContent = ((Date.now()-el._t0)/1000).toFixed(1)+'s' + (extra ? ' · '+extra : '');
}
function stepSkip(name, label) {
  const el = $('step-' + name); if (!el) return;
  if (el._timer) { clearInterval(el._timer); el._timer = null; }
  el.className = 'step'; el.querySelector('.dot').textContent = '—'; el.style.opacity = '0.4';
  const t = $('time-' + name); if (t) t.textContent = label || '';
}
function stepError(name, msg) {
  const el = $('step-' + name); if (!el) return;
  if (el._timer) { clearInterval(el._timer); el._timer = null; }
  el.className = 'step error'; el.querySelector('.dot').textContent = '✗';
  const t = $('time-' + name); if (t) t.textContent = msg || '';
}
function resetSteps() {
  ['create','analyze','artists','mixes','prompt'].forEach((n, i) => {
    const el = $('step-' + n);
    el.className = 'step'; el.style.opacity = '';
    el.querySelector('.dot').textContent = String(i+1);
    const t = $('time-' + n); if (t) t.textContent = '';
    if (el._timer) { clearInterval(el._timer); el._timer = null; }
  });
}

function renderCell(item) {
  const st = item.status || 'pending';
  const catCls = item.artist_kind === 'famous' ? 'famous' : 'mix';
  let imgHtml;
  if (st === 'done' && item.image_url) imgHtml = '<img loading="lazy" data-url="'+esc(item.image_url)+'" src="'+esc(item.thumb_url||item.image_url)+'" alt="">';
  else if (st === 'error') imgHtml = '<div class="ph err">❌ '+esc(item.error||'erreur')+'</div>';
  else if (st === 'running') imgHtml = '<div class="ph shimmer"><span class="spinner"></span> Génération…</div>';
  else imgHtml = '<div class="ph">⏳ En attente</div>';

  const editBtn = (st === 'pending' || st === 'error')
    ? '<button class="btn-mini" data-act="save" data-id="'+item.id+'">💾 Enregistrer</button>'
    : '<button class="btn-mini" data-act="regen" data-id="'+item.id+'">🔄 Regénérer</button>';

  const versionsHtml = (item.versions || []).map(v => {
    const vst = v.status || 'pending';
    let vimg;
    if (vst === 'done' && v.image_url) vimg = '<img loading="lazy" data-url="'+esc(v.image_url)+'" src="'+esc(v.thumb_url||v.image_url)+'" alt="">';
    else if (vst === 'error') vimg = '<div class="ph err">❌</div>';
    else if (vst === 'running') vimg = '<div class="ph shimmer"><span class="spinner"></span></div>';
    else vimg = '<div class="ph">⏳</div>';
    const vEditBtn = (vst === 'pending' || vst === 'error')
      ? '<button class="btn-mini" data-act="save" data-id="'+v.id+'">💾 Enregistrer</button>'
      : '<button class="btn-mini" data-act="regen" data-id="'+v.id+'">🔄 Regénérer</button>';
    return `<div class="version" data-vid="${v.id}">
      <div class="v-header"><span>#${v.id} · ${vst}</span></div>
      <div class="v-img">${vimg}</div>
      <details class="ic-prompt-wrap"><summary>📝 Prompt</summary>
        <textarea class="ic-prompt" data-pid="${v.id}">${esc(v.prompt)}</textarea>
        <div class="ic-actions"><button class="btn-mini" data-act="copy" data-id="${v.id}">📋 Copier</button>${vEditBtn}</div>
      </details>
    </div>`;
  }).join('');

  return `<div class="img-cell" data-id="${item.id}" data-status="${st}">
    <div class="ic-head"><span class="ic-name" title="${esc(item.artist_name)}">${esc(item.artist_name)}</span><span class="ic-cat ${catCls}">${catCls}</span></div>
    <div class="ic-img">${imgHtml}</div>
    <details class="ic-prompt-wrap"><summary>📝 Prompt complet</summary>
      <textarea class="ic-prompt" data-pid="${item.id}">${esc(item.prompt)}</textarea>
      <div class="ic-actions"><button class="btn-mini" data-act="copy" data-id="${item.id}">📋 Copier</button>${editBtn}</div>
    </details>
    <div class="versions">${versionsHtml}</div>
  </div>`;
}

async function loadGallery() {
  if (!CURRENT_BATCH) return;
  try {
    const r = await api('gallery', { batch_id: CURRENT_BATCH, page: VIEW.page, per_page: VIEW.per, filter: VIEW.filter }, 'GET', 20000);
    VIEW.items = r.items || [];
    VIEW.total = r.total; VIEW.pages = r.pages; VIEW.counts = r.counts;
    renderGallery(); renderPager(); updateProgress(r.counts);
  } catch (e) { toast('Galerie : '+e.message, 'err'); }
}

function renderGallery() {
  const grid = $('grid');
  if (!VIEW.items.length) { grid.innerHTML = ''; $('empty').classList.remove('hidden'); return; }
  $('empty').classList.add('hidden');
  grid.innerHTML = VIEW.items.map(renderCell).join('');
  grid.querySelectorAll('.ic-img img, .v-img img').forEach(img => {
    if (img.complete) img.classList.add('loaded');
    else img.addEventListener('load', () => img.classList.add('loaded'), {once:true});
  });
}

function renderPager() {
  const pager = $('pager');
  const { page, pages, total } = VIEW;
  if (pages <= 1) { pager.innerHTML = `<span class="info">${total} artiste(s)</span>`; return; }
  const range = [];
  const push = p => { if (!range.includes(p)) range.push(p); };
  push(1); push(pages);
  for (let i = Math.max(1, page-2); i <= Math.min(pages, page+2); i++) push(i);
  range.sort((a,b)=>a-b);
  const html = [];
  html.push(`<button class="pg" data-pg="1" ${page<=1?'disabled':''}>«</button>`);
  html.push(`<button class="pg" data-pg="${page-1}" ${page<=1?'disabled':''}>‹</button>`);
  let prev = 0;
  for (const p of range) {
    if (prev && p - prev > 1) html.push('<span class="dots">…</span>');
    html.push(`<button class="pg ${p===page?'on':''}" data-pg="${p}">${p}</button>`);
    prev = p;
  }
  html.push(`<button class="pg" data-pg="${page+1}" ${page>=pages?'disabled':''}>›</button>`);
  html.push(`<button class="pg" data-pg="${pages}" ${page>=pages?'disabled':''}>»</button>`);
  html.push(`<span class="info">${total} artiste(s) · ${page}/${pages}</span>`);
  pager.innerHTML = html.join('');
  pager.querySelectorAll('[data-pg]').forEach(b => b.onclick = () => { VIEW.page = +b.dataset.pg; loadGallery(); });
}

function updateProgress(counts) {
  if (!counts) return;
  const total = counts.total || 0;
  const done = counts.done || 0, run = counts.running || 0, err = counts.error || 0, pend = counts.pending || 0;
  const denom = total || 1;
  $('barDone').style.width = (done/denom*100)+'%';
  $('barRun').style.width = (run/denom*100)+'%';
  $('barErr').style.width = (err/denom*100)+'%';
  $('statDone').textContent = done + ' / ' + total;
  $('statRun').textContent = run;
  $('statErr').textContent = err + (pend ? ' · '+pend+' en attente' : '');
  let line = '';
  if (RUN.running) {
    line = RUN.active + ' workers actifs';
    const el = (Date.now() - RUN.t0) / 60000;
    const progressed = done - RUN.done0;
    if (progressed >= 3 && el > 0.1) {
      const rate = progressed / el;
      line += ' · ' + rate.toFixed(1) + ' img/min';
      const remaining = total - done - err;
      if (remaining > 0) line += ' · ≈ ' + Math.max(1, Math.ceil(remaining / rate)) + ' min';
    }
  }
  if (err) line += (line?' · ':'') + '⚠️ '+err+' erreur(s)';
  $('etaLine').textContent = line;
}

function setRunUi() {
  $('startBtn').disabled = RUN.running;
  $('startBtn').textContent = RUN.running ? '⏳ Génération en cours…' : '🚀 Lancer la génération';
  $('pauseBtn').classList.toggle('hidden', !RUN.running || RUN.paused);
  $('resumeBtn').classList.toggle('hidden', !RUN.running || !RUN.paused);
  $('stopBtn').classList.toggle('hidden', !RUN.running);
}

async function workerOne() {
  while (RUN.running && !RUN.stop && CURRENT_BATCH) {
    if (RUN.paused) { await sleep(400); continue; }
    if (!API_KEYS.length) { await sleep(1500); continue; }
    const keys = API_KEYS.map(e => e.key);
    RUN.active++;
    try {
      const r = await api('process_one', { batch_id: CURRENT_BATCH, keys, count: IMG_PER_WORKER, user_id: CFG.userId }, 'POST', TIMEOUTS.process_one);
      if (r.done) {
        if (SETUP_RUNNING && !RUN.stop) { await sleep(1000); continue; }
        if (!r.busy) return;
        await sleep(1500);
        continue;
      }
      if (r.results) for (const res of r.results) if (res.ok) updateImageInDom(res.id, res.url, res.thumb);
    } catch (e) {
      if (e.status === 429) await sleep(3000); else await sleep(500);
    } finally { RUN.active--; }
  }
}

function updateImageInDom(id, url, thumb) {
  const cell = document.querySelector(`.img-cell[data-id="${id}"]`);
  if (cell) {
    const box = cell.querySelector('.ic-img');
    if (box) {
      const img = new Image();
      img.loading = 'lazy';
      img.onload = () => img.classList.add('loaded');
      img.onclick = () => openLightbox(url);
      img.src = thumb || url; img.dataset.url = url;
      box.replaceChildren(img);
    }
    cell.dataset.status = 'done';
    const det = cell.querySelector('.ic-prompt-wrap');
    if (det) {
      const saveBtn = det.querySelector('[data-act="save"]');
      if (saveBtn) {
        const regen = document.createElement('button');
        regen.className = 'btn-mini'; regen.dataset.act = 'regen'; regen.dataset.id = id; regen.textContent = '🔄 Regénérer';
        saveBtn.replaceWith(regen);
      }
    }
  }
  const v = document.querySelector(`.version[data-vid="${id}"]`);
  if (v) {
    const box = v.querySelector('.v-img');
    if (box) {
      const img = new Image();
      img.loading = 'lazy';
      img.onload = () => img.classList.add('loaded');
      img.onclick = () => openLightbox(url);
      img.src = thumb || url; img.dataset.url = url;
      box.replaceChildren(img);
    }
    const h = v.querySelector('.v-header span');
    if (h) h.textContent = h.textContent.replace(/\s·\s.*$/, ' · done');
    const saveBtn = v.querySelector('[data-act="save"]');
    if (saveBtn) {
      const regen = document.createElement('button');
      regen.className = 'btn-mini'; regen.dataset.act = 'regen'; regen.dataset.id = id; regen.textContent = '🔄 Regénérer';
      saveBtn.replaceWith(regen);
    }
  }
}

function startPolling() {
  stopPolling();
  VIEW.polling = true;
  const loop = async () => {
    if (!VIEW.polling) return;
    try {
      const r = await api('batch_status', { batch_id: CURRENT_BATCH }, 'GET', 10000);
      VIEW.counts = r.counts;
      updateProgress(r.counts);
      if (!$('grid').querySelector('.img-cell:not([data-status="done"])')) await loadGallery();
    } catch (e) {}
    if (VIEW.polling) VIEW.pollTimer = setTimeout(loop, document.hidden ? 5000 : 2000);
  };
  VIEW.pollTimer = setTimeout(loop, 500);
}
function stopPolling() { VIEW.polling = false; clearTimeout(VIEW.pollTimer); }

async function runPipeline() {
  if (RUN.running) return;
  const prompt = $('prompt').value.trim();
  const targetFamous = Math.max(1, parseInt($('numFamous').value, 10) || 200);
  const targetMix = Math.max(0, parseInt($('numMix').value, 10) || 0);
  const ratio = $('ratio').value;
  const size = $('size').value;
  const workersN = parseInt($('workers').value, 10) || 10;
  const skipAn = $('skipAnalysis').checked;
  const hasImage = !!CURRENT_IMAGE;

  if (!prompt && !hasImage) { toast('Prompt ou image requis', 'err'); return; }
  if (!API_KEYS.length) { toast('Ajoutez vos clés API (🔑)', 'err'); $('apiPanel').classList.add('show'); return; }

  const mustAnalyze = hasImage && !prompt;
  const willAnalyze = hasImage && (mustAnalyze || !skipAn);

  resetSteps();
  $('analysisBox').classList.add('hidden');
  $('startBtn').disabled = true;
  RUN.stop = false;

  try {
    stepStart('create');
    $('startStatus').className = 'status';
    $('startStatus').innerHTML = '<span class="spinner"></span> Création du batch…';
    const cb = await api('create_batch', { prompt, famous: targetFamous, mix: targetMix, ratio, size }, 'POST', TIMEOUTS.create_batch);
    CURRENT_BATCH = cb.batch_id;
    VIEW.page = 1;
    VIEW.counts = { done: 0, running: 0, error: 0, pending: 0, total: 0 };
    stepDone('create');
    await loadGallery();

    if (willAnalyze) {
      stepStart('analyze');
      $('startStatus').className = 'status';
      $('startStatus').innerHTML = '<span class="spinner"></span> Extraction de l\'essence de l\'image…';
      let ok = false, lastErr = '';
      for (let attempt = 0; attempt < 3 && !ok && !RUN.stop; attempt++) {
        if (attempt > 0) await sleep(2000);
        try {
          const ai = await api('analyze_image', { batch_id: CURRENT_BATCH, image: CURRENT_IMAGE, keys: API_KEYS.map(e=>e.key) }, 'POST', TIMEOUTS.analyze_image);
          $('analysisBox').textContent = '🔍 ' + ai.description;
          $('analysisBox').classList.remove('hidden');
          ok = true;
        } catch (e) { lastErr = e.message; }
      }
      if (ok) stepDone('analyze');
      else if (prompt) stepDone('analyze', 'échec · prompt seul');
      else { stepError('analyze', lastErr); throw new Error('Analyse échouée : ' + lastErr); }
    } else if (hasImage) stepSkip('analyze', 'ignorée (rapide)');
    else stepSkip('analyze', 'pas d\'image');

    SETUP_RUNNING = true;
    Object.assign(RUN, { running: true, paused: false, stop: false, active: 0, t0: Date.now(), done0: 0 });
    setRunUi();
    startPolling();
    const workersPromise = Promise.all(Array.from({length: workersN}, () => workerOne()));

    stepStart('artists');
    let current = 0, rounds = 0, fails = 0;
    while (current < targetFamous && rounds < 10 && !RUN.stop) {
      $('startStatus').className = 'status';
      $('startStatus').innerHTML = `<span class="spinner"></span> Artistes : ${current}/${targetFamous} · générations en cours : ${VIEW.counts?.running || 0}`;
      try {
        const want = rounds === 0 ? 80 : Math.min(80, targetFamous - current + 20);
        const e = await api('expand_artists', { batch_id: CURRENT_BATCH, want, target: targetFamous, keys: API_KEYS.map(x=>x.key) }, 'POST', TIMEOUTS.expand_artists);
        current = e.total || current;
        rounds++; fails = 0;
        if (e.done) break;
        if ((e.added || 0) === 0) fails++;
        if (fails >= 2) break;
      } catch (e) { fails++; rounds++; if (fails >= 3) break; await sleep(1500); }
    }
    stepDone('artists', current + ' artistes');

    if (targetMix > 0 && !RUN.stop) {
      stepStart('mixes');
      $('startStatus').className = 'status';
      $('startStatus').innerHTML = '<span class="spinner"></span> Génération des mixes…';
      await api('build_mixes', { batch_id: CURRENT_BATCH, count: targetMix }, 'POST', TIMEOUTS.build_mixes);
      stepDone('mixes');
    } else stepSkip('mixes');

    stepStart('prompt');
    const mi = await api('materialize_images', { batch_id: CURRENT_BATCH }, 'POST', TIMEOUTS.materialize_images);
    stepDone('prompt', mi.total + ' prompts');
    $('batchInfo').textContent = 'Batch #' + CURRENT_BATCH + ' · ' + mi.total + ' images au total';

    SETUP_RUNNING = false;
    await loadGallery();
    await workersPromise;
  } catch (e) {
    SETUP_RUNNING = false;
    $('startStatus').className = 'status err';
    $('startStatus').textContent = '❌ ' + e.message;
    toast(e.message, 'err');
    RUN.stop = true;
  }

  RUN.running = false;
  stopPolling();
  await loadGallery().catch(()=>{});
  setRunUi();
  $('startBtn').disabled = false;
  const c = VIEW.counts || {};
  $('startStatus').className = 'status ' + (RUN.stop ? 'warn' : 'ok');
  $('startStatus').textContent = RUN.stop ? '⏹ Arrêté' : ('✓ Terminé — ' + (c.done||0) + ' images' + (c.error ? ' · ' + c.error + ' erreurs' : ''));
  loadBatches();
}

async function regenPrompt(id, prompt) {
  const cell = document.querySelector(`.img-cell[data-id="${id}"], .version[data-vid="${id}"]`);
  if (!cell) return;
  try {
    const r = await api('regenerate', { image_id: id, prompt });
    const rootCell = cell.closest('.img-cell');
    const versions = rootCell.querySelector('.versions');
    const vEl = document.createElement('div');
    vEl.className = 'version'; vEl.dataset.vid = r.id;
    vEl.innerHTML = `<div class="v-header"><span>#${r.id} · pending</span></div>
      <div class="v-img"><div class="ph shimmer"><span class="spinner"></span></div></div>
      <details class="ic-prompt-wrap"><summary>📝 Prompt</summary>
        <textarea class="ic-prompt" data-pid="${r.id}">${esc(prompt)}</textarea>
        <div class="ic-actions"><button class="btn-mini" data-act="copy" data-id="${r.id}">📋 Copier</button></div>
      </details>`;
    versions.appendChild(vEl);
    toast('Nouvelle version en cours…', 'ok');
    if (!RUN.running) {
      Object.assign(RUN, { running: true, paused: false, stop: false, active: 0, t0: Date.now(), done0: VIEW.counts?.done || 0 });
      SETUP_RUNNING = false;
      setRunUi();
      startPolling();
      const n = parseInt($('workers').value, 10) || 10;
      Promise.all(Array.from({length: n}, () => workerOne())).then(async () => {
        RUN.running = false;
        stopPolling();
        await loadGallery().catch(()=>{});
        setRunUi();
      });
    }
  } catch (e) { toast(e.message, 'err'); }
}

async function savePrompt(id, prompt) { try { await api('save_prompt', { id, prompt }); toast('Enregistré', 'ok'); } catch (e) { toast(e.message, 'err'); } }

function attachGridHandlers() {
  $('grid').addEventListener('click', async e => {
    const img = e.target.closest('.ic-img img, .v-img img');
    if (img && img.dataset.url) { openLightbox(img.dataset.url); return; }
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const act = btn.dataset.act, id = +btn.dataset.id;
    if (act === 'copy') { const ta = document.querySelector(`[data-pid="${id}"]`); if (ta) await copyText(ta.value); }
    else if (act === 'save') { const ta = document.querySelector(`[data-pid="${id}"]`); if (ta) await savePrompt(id, ta.value.trim()); }
    else if (act === 'regen') { const ta = document.querySelector(`[data-pid="${id}"]`); if (!ta) return; const p = ta.value.trim(); if (!p) { toast('Prompt vide', 'err'); return; } await regenPrompt(id, p); }
  });
}

async function loadBatches() {
  try {
    const r = await api('get_state', {}, 'GET');
    const bs = r.batches || [];
    const list = $('batchesList');
    if (!bs.length) { list.innerHTML = '<div style="color:var(--ink3);font-size:.8rem">Aucun batch.</div>'; return; }
    list.innerHTML = bs.map(b => {
      const title = (b.input_text || b.image_desc || '(image)').slice(0, 80);
      return `<div class="batch-row ${b.id===CURRENT_BATCH?'active':''}" data-bid="${b.id}">
        <span class="bt">#${b.id} · ${esc(title)}</span>
        <span class="bs">${b.images_done}/${b.images_total} faites${b.images_error?' · '+b.images_error+' err':''}</span>
        <button class="btn-mini" data-del="${b.id}">✕</button>
      </div>`;
    }).join('');
    list.querySelectorAll('.batch-row').forEach(row => {
      row.onclick = e => {
        if (e.target.closest('[data-del]')) return;
        CURRENT_BATCH = +row.dataset.bid;
        VIEW.page = 1;
        loadGallery(); loadBatches();
        $('batchInfo').textContent = 'Batch #' + CURRENT_BATCH + ' (chargé)';
      };
    });
    list.querySelectorAll('[data-del]').forEach(btn => btn.onclick = async e => {
      e.stopPropagation();
      const id = +btn.dataset.del;
      if (!confirm('Supprimer le batch #' + id + ' ?')) return;
      try {
        await api('delete_batch', { batch_id: id });
        if (CURRENT_BATCH === id) { CURRENT_BATCH = 0; $('grid').innerHTML = ''; VIEW.counts = null; }
        loadBatches(); if (CURRENT_BATCH) loadGallery();
      } catch (err) { toast(err.message, 'err'); }
    });
  } catch (e) { console.error(e); }
}

async function init() {
  updateApiTag();
  await loadKeysFromServer();

  $('openApiBtn').onclick = () => $('apiPanel').classList.toggle('show');
  $('closeApiBtn').onclick = () => $('apiPanel').classList.remove('show');
  $('saveKeys').onclick = saveKeysToServer;
  $('apiKeys').addEventListener('blur', saveKeysToServer);
  $('testKeys').onclick = async () => {
    const entries = parseKeysText($('apiKeys').value);
    if (!entries.length) { toast('Aucune clé', 'err'); return; }
    $('apiStatus').className = 'status';
    $('apiStatus').innerHTML = '<span class="spinner"></span> Test…';
    try {
      const r = await api('test_keys', { keys: entries.map(e=>e.key) }, 'POST', 40000);
      const ok = (r.results||[]).filter(x=>x.ok).length;
      $('apiStatus').className = 'status ' + (ok===entries.length?'ok':(ok?'warn':'err'));
      $('apiStatus').textContent = '✓ ' + ok + '/' + entries.length + ' valides';
    } catch (e) { $('apiStatus').className = 'status err'; $('apiStatus').textContent = '❌ ' + e.message; }
  };

  $('imgInput').addEventListener('change', async e => { const f = e.target.files[0]; if (f) await handleImage(f); e.target.value=''; });
  const drop = $('drop');
  ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = 'var(--violet)'; }));
  ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.style.borderColor = ''; }));
  drop.addEventListener('drop', async e => { const f = e.dataTransfer.files[0]; if (f) await handleImage(f); });

  $('startBtn').onclick = runPipeline;
  $('pauseBtn').onclick = () => { RUN.paused = true; setRunUi(); };
  $('resumeBtn').onclick = () => { RUN.paused = false; setRunUi(); };
  $('stopBtn').onclick = () => { RUN.stop = true; setRunUi(); };
  $('resetBtn').onclick = async () => {
    if (!confirm('Tout effacer ?')) return;
    try {
      await api('reset_all', {});
      CURRENT_BATCH = 0; VIEW.items = []; VIEW.counts = null;
      renderGallery(); renderPager();
      updateProgress({total:0,done:0,running:0,error:0,pending:0});
      $('batchInfo').textContent = '';
      resetSteps();
      loadBatches();
      toast('Reset', 'ok');
    } catch (e) { toast(e.message, 'err'); }
  };

  $('perPage').onchange = () => { VIEW.per = +$('perPage').value; VIEW.page = 1; loadGallery(); };
  $('galFilter').onchange = () => { VIEW.filter = $('galFilter').value; VIEW.page = 1; loadGallery(); };

  $('lightbox').onclick = e => { if (e.target.id !== 'lbDl') $('lightbox').classList.remove('on'); };
  document.addEventListener('keydown', e => { if (e.key === 'Escape') $('lightbox').classList.remove('on'); });

  attachGridHandlers();

  try {
    const st = await api('get_state', {}, 'GET');
    if (st.current) {
      const b = st.current;
      CURRENT_BATCH = b.id;
      $('batchInfo').textContent = 'Batch #' + b.id + ' · ' + b.images_done + '/' + b.images_total + ' faites' + (b.images_error ? ' · ' + b.images_error + ' err' : '');
      await loadGallery();
      if (b.image_desc) { $('analysisBox').textContent = '🔍 ' + b.image_desc; $('analysisBox').classList.remove('hidden'); }
      if (b.images_total > 0) ['create','analyze','artists','mixes','prompt'].forEach(n => stepDone(n));
      if (b.images_total > (b.images_done + b.images_error)) {
        Object.assign(RUN, { running: true, paused: false, stop: false, active: 0, t0: Date.now(), done0: b.images_done });
        SETUP_RUNNING = false;
        setRunUi();
        startPolling();
        const n = parseInt($('workers').value, 10) || 10;
        toast('Reprise auto…', 'ok');
        Promise.all(Array.from({length: n}, () => workerOne())).then(async () => {
          RUN.running = false;
          stopPolling();
          await loadGallery().catch(()=>{});
          setRunUi();
        });
      }
    }
    loadBatches();
  } catch (e) { console.error(e); loadBatches(); }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
</script>
</body>
</html>
