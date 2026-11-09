<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json');

ytdl_maintenance_gate(ytdl_current_user(), true);
ytdl_purge_expired_tokens();

// Le serveur choisit le seul compte proposé : aucun identifiant arbitraire.
$user = ytdl_welcome_account();
if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'Aucun compte disponible.']);
    exit;
}

// Si ce téléphone/navigateur a déjà un token valide pour ce compte (posé en
// cookie lors d'une connexion précédente), on l'utilise directement plutôt
// que de renvoyer un email — évite d'attendre un mail à chaque connexion.
$cookieToken = $_COOKIE['ytdl_token'] ?? '';
if ($cookieToken !== '') {
    $check = ytdl_db()->prepare("SELECT * FROM login_tokens WHERE token = :token AND user_id = :user_id AND expires_at >= datetime('now')");
    $check->execute([':token' => $cookieToken, ':user_id' => $user['id']]);
    if ($check->fetch()) {
        $_SESSION['user_id'] = $user['id'];
        echo json_encode(['ok' => true, 'direct' => true]);
        exit;
    }
}

$remember = ($_GET['remember'] ?? '0') === '1';

$token = bin2hex(random_bytes(32));
// "Rester connecté indéfiniment" : pas de vraie infinité en base, mais 10 ans
// est en pratique équivalent pour un usage personnel.
$expiresAt = (new DateTime($remember ? '+10 years' : '+48 hours'))->format('Y-m-d H:i:s');

$insert = ytdl_db()->prepare('INSERT INTO login_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)');
$insert->execute([':user_id' => $user['id'], ':token' => $token, ':expires_at' => $expiresAt]);

[$codeRequest, $code] = ytdl_attach_login_code($token);
$sent = ytdl_send_login_email($user, $token, $code);

if (!$sent) {
    http_response_code(500);
    echo json_encode(['error' => "L'envoi de l'email a échoué (serveur mail non configuré ?)."]);
    exit;
}

echo json_encode(['ok' => true, 'direct' => false, 'code_request' => $codeRequest]);
