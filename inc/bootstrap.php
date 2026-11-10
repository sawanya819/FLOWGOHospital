<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
$GLOBALS['CONFIG'] = require APP_ROOT . '/config.php';
date_default_timezone_set($GLOBALS['CONFIG']['timezone'] ?? 'Asia/Bangkok');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('HPQSESS');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/** base URL path ของแอป (เช่น /hp2569) คำนวณจากตำแหน่งไฟล์ */
function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
        $app = realpath(APP_ROOT) ?: APP_ROOT;
        $base = '';
        if ($docRoot !== '' && stripos($app, $docRoot) === 0) {
            $base = str_replace('\\', '/', substr($app, strlen($docRoot)));
        } else {
            // กรณีใช้ Alias ของ Apache: ใช้ SCRIPT_NAME เทียบกับตำแหน่งไฟล์
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
            $file = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
            $rel = str_replace('\\', '/', substr($file, strlen(str_replace('\\', '/', $app))));
            if ($rel !== '' && str_ends_with($script, $rel)) {
                $base = substr($script, 0, -strlen($rel));
            }
        }
        $base = rtrim($base, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['CONFIG']['db'];
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : base_url($path)));
    exit;
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------- CSRF ---------------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'json') || isset($_SERVER['HTTP_X_CSRF'])) {
            json_out(['ok' => false, 'error' => 'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่'], 419);
        }
        http_response_code(419);
        exit('เซสชันหมดอายุ กรุณากลับไปโหลดหน้าใหม่');
    }
}

/* ---------------- Settings ---------------- */
function setting(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT skey, svalue FROM settings') as $r) {
                $cache[$r['skey']] = $r['svalue'];
            }
        } catch (Throwable) {
        }
    }
    return $cache[$key] ?? $default;
}

/* ---------------- จอฝั่งผู้ป่วยที่เคาน์เตอร์ ---------------- */
/** สั่งให้จอฝั่งผู้ป่วยของเคาน์เตอร์ (ผูกกับบัญชีเจ้าหน้าที่) แสดงคิว $queueId หรือล้างจอเมื่อเป็น null */
function counter_screen_push(int $userId, ?int $queueId): void
{
    $val = $queueId ? json_encode(['id' => $queueId, 'at' => time()]) : null;
    db()->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')
        ->execute(['counter_screen_' . $userId, $val]);
}

function counter_screen_get(int $userId): ?array
{
    $st = db()->prepare('SELECT svalue FROM settings WHERE skey = ?');
    $st->execute(['counter_screen_' . $userId]);
    $v = json_decode((string)$st->fetchColumn(), true);
    return is_array($v) ? $v : null;
}

/* ---------------- Staff auth ---------------- */
const ROLES = [
    'admin'  => 'ผู้ดูแลระบบ',
    'staff'  => 'เจ้าหน้าที่ต้อนรับ',
    'doctor' => 'แพทย์/พยาบาล',
];

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_role(string ...$roles): array
{
    $u = current_user();
    if (!$u) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_out(['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบ'], 401);
        }
        redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
    if ($roles && !in_array($u['role'], $roles, true) && $u['role'] !== 'admin') {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_out(['ok' => false, 'error' => 'ไม่มีสิทธิ์ใช้งาน'], 403);
        }
        http_response_code(403);
        exit('ไม่มีสิทธิ์เข้าถึงหน้านี้');
    }
    return $u;
}

/* ---------------- Patient auth ---------------- */
function current_patient(): ?array
{
    return $_SESSION['patient'] ?? null;
}

function require_patient(): array
{
    $p = current_patient();
    if (!$p) {
        if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            json_out(['ok' => false, 'error' => 'กรุณาเข้าสู่ระบบด้วย HN'], 401);
        }
        redirect('patient/login.php');
    }
    return $p;
}

/* ---------------- Formatting ---------------- */
const TH_MONTHS = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

function thai_date(?string $date, bool $withTime = false): string
{
    if (!$date) return '-';
    $ts = strtotime($date);
    if ($ts === false) return e($date);
    $s = (int)date('j', $ts) . ' ' . TH_MONTHS[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);
    return $withTime ? $s . ' ' . date('H:i', $ts) . ' น.' : $s;
}

function mask_name(string $fname, string $lname): string
{
    $l = mb_substr($lname, 0, 1) . str_repeat('x', max(0, min(4, mb_strlen($lname) - 1)));
    return trim($fname . ' ' . $l);
}

require_once __DIR__ . '/his.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/layout.php';
