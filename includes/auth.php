<?php

function is_https_request(): bool
{
    // Derrière Cloudflare (Flexible SSL), l'origine IONOS ne voit jamais HTTPS
    // directement : Cloudflare termine le TLS et transmet en HTTP, en indiquant
    // le protocole d'origine via X-Forwarded-Proto.
    return !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('cave_session');
    session_start();
}

function is_logged_in(): bool
{
    start_secure_session();
    return !empty($_SESSION['admin_logged_in']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /pages/login.php');
        exit;
    }
}

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 900; // 15 minutes
const LOGIN_BLACKLIST_SECONDS = 86400; // 24 heures — identifiant différent de l'admin (signe de scan/bot)

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function login_locked_until(PDO $db, string $ip): ?string
{
    $stmt = $db->prepare('SELECT locked_until FROM login_attempts WHERE ip_address = ?');
    $stmt->execute([$ip]);
    $lockedUntil = $stmt->fetchColumn();
    if ($lockedUntil && strtotime($lockedUntil) > time()) {
        return $lockedUntil;
    }
    return null;
}

function register_failed_login(PDO $db, string $ip): void
{
    $stmt = $db->prepare('SELECT attempts FROM login_attempts WHERE ip_address = ?');
    $stmt->execute([$ip]);
    $attempts = (int) $stmt->fetchColumn() + 1;
    $lockedUntil = $attempts >= LOGIN_MAX_ATTEMPTS ? date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_SECONDS) : null;

    $db->prepare(
        'INSERT INTO login_attempts (ip_address, attempts, last_attempt, locked_until) VALUES (:ip, :attempts, NOW(), :locked_until)
         ON DUPLICATE KEY UPDATE attempts = :attempts2, last_attempt = NOW(), locked_until = :locked_until2'
    )->execute([
        'ip' => $ip,
        'attempts' => $attempts,
        'locked_until' => $lockedUntil,
        'attempts2' => $attempts,
        'locked_until2' => $lockedUntil,
    ]);
}

function clear_login_attempts(PDO $db, string $ip): void
{
    $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);
}

function blacklist_ip(PDO $db, string $ip, int $seconds): void
{
    $lockedUntil = date('Y-m-d H:i:s', time() + $seconds);
    $db->prepare(
        'INSERT INTO login_attempts (ip_address, attempts, last_attempt, locked_until) VALUES (:ip, :attempts, NOW(), :locked_until)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW(), locked_until = :locked_until2'
    )->execute([
        'ip' => $ip,
        'attempts' => LOGIN_MAX_ATTEMPTS,
        'locked_until' => $lockedUntil,
        'locked_until2' => $lockedUntil,
    ]);
}

function admin_password_hash(PDO $db): string
{
    return get_setting($db, 'admin_password_hash') ?? ADMIN_PASSWORD_HASH;
}

function attempt_login(PDO $db, string $username, string $password): bool
{
    start_secure_session();
    if (hash_equals(ADMIN_USERNAME, $username) && password_verify($password, admin_password_hash($db))) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        return true;
    }
    return false;
}

function logout(): void
{
    start_secure_session();
    $_SESSION = [];
    session_destroy();
}

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Requête invalide (CSRF).');
    }
}
