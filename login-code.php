<?php
/**
 * Connexion par le code à 6 chiffres de l'email (voir ytdl_attach_login_code).
 *
 * Sur iPhone, l'appli ajoutée à l'écran d'accueil garde ses propres cookies :
 * le lien de l'email s'ouvre dans le navigateur et connecte celui-ci, pas
 * l'appli. Taper le code dans l'appli la connecte, exactement comme le lien
 * (cookie de reconnexion compris).
 *
 * Entrée (POST) : request = clé reçue lors de la demande du lien, code.
 * 5 essais par demande, code valable 15 minutes, utilisable une seule fois.
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
ytdl_maintenance_gate(null, true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

$request = (string)($_POST['request'] ?? '');
$code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
if (!preg_match('/^[a-f0-9]{32}$/D', $request) || strlen($code) !== 6) {
    ytdl_json_error('Tape les 6 chiffres reçus par email.', 400);
}

$db = ytdl_db();
$stmt = $db->prepare("SELECT * FROM login_tokens WHERE code_request = :request AND code_hash IS NOT NULL
    AND code_expires_at >= datetime('now') AND expires_at >= datetime('now')");
$stmt->execute([':request' => $request]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt = null;

if (!$row || (int)$row['code_attempts'] >= 5) {
    ytdl_json_error('Ce code a expiré : redemande un lien de connexion.', 400);
}

if (!hash_equals($row['code_hash'], hash('sha256', $row['token'] . '|' . $code))) {
    $db->prepare('UPDATE login_tokens SET code_attempts = code_attempts + 1 WHERE id = :id')->execute([':id' => $row['id']]);
    $left = 4 - (int)$row['code_attempts'];
    ytdl_json_error($left > 0 ? 'Code incorrect (encore ' . $left . ' essai' . ($left > 1 ? 's' : '') . ').'
        : 'Code incorrect. Redemande un lien de connexion.', 400);
}

$userStmt = $db->prepare("SELECT * FROM users WHERE id = :id AND status = 'active'");
$userStmt->execute([':id' => $row['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    ytdl_json_error("Ce compte n'est plus actif.", 403);
}

// Code à usage unique ; le jeton, lui, reste valable comme avec le lien.
$db->prepare('UPDATE login_tokens SET code_hash = NULL, code_request = NULL WHERE id = :id')->execute([':id' => $row['id']]);

// Mêmes effets que verify.php : reconnexion directe ensuite sur cet appareil.
setcookie('ytdl_token', $row['token'], ytdl_cookie_options(strtotime($row['expires_at'] . ' UTC')));
ytdl_remember_account($user);
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];

echo json_encode(['ok' => true]);
