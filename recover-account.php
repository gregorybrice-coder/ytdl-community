<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
ytdl_maintenance_gate(null, true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée.']);
    exit;
}
$email = $_POST['email'] ?? '';
if (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Adresse email invalide.']);
    exit;
}
$email = trim($email);
$db = ytdl_db();
$now = time();
$emailHash = hash('sha256', strtolower($email));
$ipHash = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
// Limites communes aux adresses connues et inconnues, sans révéler les comptes.
$db->exec('BEGIN IMMEDIATE');
try {
    $db->prepare('DELETE FROM account_recovery_attempts WHERE attempted_at < ?')->execute([$now - 3600]);
    $query = $db->prepare('SELECT count(*) FROM account_recovery_attempts WHERE ip_hash = ?');
    $query->execute([$ipHash]);
    $ipCount = (int)$query->fetchColumn();
    $query = $db->prepare('SELECT count(*) FROM account_recovery_attempts WHERE email_hash = ? AND attempted_at > ?');
    $query->execute([$emailHash, $now - 60]);
    $limited = $ipCount >= 10 || (int)$query->fetchColumn() > 0;
    if (!$limited) {
        $db->prepare('INSERT INTO account_recovery_attempts VALUES (?, ?, ?)')->execute([$emailHash, $ipHash, $now]);
    }
    $db->exec('COMMIT');
} catch (Throwable $error) {
    $db->exec('ROLLBACK');
    throw $error;
}
if ($limited) {
    http_response_code(429);
    echo json_encode(['error' => 'Patiente avant de demander un nouveau lien, puis réessaie.']);
    exit;
}
$query = $db->prepare("SELECT * FROM users WHERE email = :email COLLATE NOCASE AND status = 'active'");
$query->execute([':email' => $email]);
$user = $query->fetch(PDO::FETCH_ASSOC);
$codeRequest = bin2hex(random_bytes(16)); // clé factice si l'adresse est inconnue
if ($user) {
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO login_tokens (user_id, token, expires_at) VALUES (?, ?, ?)')
        ->execute([$user['id'], $token, gmdate('Y-m-d H:i:s', $now + 48 * 3600)]);
    [$codeRequest, $code] = ytdl_attach_login_code($token);
    if (!ytdl_send_login_email($user, $token, $code)) {
        $db->prepare('DELETE FROM login_tokens WHERE token = ?')->execute([$token]);
        error_log('YTDL: account recovery email delivery failed.');
    }
}
// Même réponse publique, que l'adresse existe ou non. verify.php (lien) ou
// login-code.php (code) restaurent les cookies.
echo json_encode(['ok' => true, 'code_request' => $codeRequest]);
