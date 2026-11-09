<?php
require_once __DIR__ . '/auth.php';

ytdl_maintenance_gate(ytdl_current_user());
ytdl_purge_expired_tokens();

$token = $_GET['token'] ?? '';

function ytdl_verify_error(string $message): never {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Lien invalide</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#0f1115;color:#e6e6e6;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}';
    echo '.box{max-width:420px;text-align:center;padding:32px;} a{color:#5cc8ff;}</style></head><body>';
    echo '<div class="box"><h1>Lien invalide ou expiré</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
    echo '<p><a href="index.php">&larr; Redemander un lien de connexion</a></p></div></body></html>';
    exit;
}

if ($token === '') {
    ytdl_verify_error('Aucun jeton fourni.');
}

$stmt = ytdl_db()->prepare('SELECT * FROM login_tokens WHERE token = :token AND expires_at >= datetime("now")');
$stmt->execute([':token' => $token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    ytdl_verify_error('Ce lien a expiré (validité 48h).');
}

$userStmt = ytdl_db()->prepare("SELECT * FROM users WHERE id = :id AND status = 'active'");
$userStmt->execute([':id' => $row['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    ytdl_verify_error('Ce compte n\'est plus actif.');
}

// Le jeton reste valide 48h (pas d'usage unique) : on le pose aussi en cookie
// sur cet appareil pour permettre une reconnexion directe sans nouvel email
// tant qu'il n'a pas expiré (voir send-login.php).
setcookie('ytdl_token', $row['token'], ytdl_cookie_options(strtotime($row['expires_at'] . ' UTC')));
ytdl_remember_account($user);
session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];

header('Location: capture.php');
