<?php
/**
 * Demande de changement d'adresse email.
 *
 * La nouvelle adresse n'est jamais écrite dans users ici : on se contente
 * d'enregistrer la demande et d'envoyer un lien de confirmation à l'ANCIENNE
 * adresse. Tant que ce lien n'est pas ouvert, le compte continue de
 * fonctionner exactement comme avant (voir confirm-email-change.php).
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

$user = ytdl_require_login();

header('Content-Type: application/json');

ytdl_purge_expired_tokens();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$newEmail = trim((string)($input['email'] ?? ''));

if ($newEmail === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nouvelle adresse email requise.']);
    exit;
}
if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Adresse email invalide.']);
    exit;
}
if (mb_strtolower($newEmail) === mb_strtolower($user['email'])) {
    http_response_code(400);
    echo json_encode(['error' => "C'est déjà ton adresse actuelle."]);
    exit;
}

$pdo = ytdl_db();

// La colonne users.email est UNIQUE : autant refuser tout de suite avec un
// message clair plutôt que de laisser échouer l'UPDATE à la confirmation,
// une fois l'email de validation déjà parti.
$taken = $pdo->prepare('SELECT COUNT(*) FROM users WHERE lower(email) = lower(:email) AND id <> :id');
$taken->execute([':email' => $newEmail, ':id' => $user['id']]);
if ((int)$taken->fetchColumn() > 0) {
    http_response_code(409);
    echo json_encode(['error' => 'Cette adresse est déjà utilisée par un autre compte.']);
    exit;
}

// Une seule demande valide à la fois par compte : redemander un lien annule
// le précédent, pour qu'un vieux lien traînant dans la boîte ne puisse pas
// appliquer une adresse qui n'est plus celle voulue.
$cancel = $pdo->prepare('DELETE FROM email_change_requests WHERE user_id = :id');
$cancel->execute([':id' => $user['id']]);

$token = bin2hex(random_bytes(32));
$expiresAt = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

$insert = $pdo->prepare(
    'INSERT INTO email_change_requests (user_id, new_email, token, expires_at) VALUES (:user_id, :new_email, :token, :expires_at)'
);
$insert->execute([
    ':user_id' => $user['id'],
    ':new_email' => $newEmail,
    ':token' => $token,
    ':expires_at' => $expiresAt,
]);

if (!ytdl_send_email_change_email($user, $newEmail, $token)) {
    // Envoi raté : la demande enregistrée serait inconfirmable, on la retire
    // plutôt que de la laisser bloquer une nouvelle tentative.
    $cleanup = $pdo->prepare('DELETE FROM email_change_requests WHERE token = :token');
    $cleanup->execute([':token' => $token]);

    http_response_code(500);
    echo json_encode(['error' => "L'envoi de l'email a échoué (serveur mail non configuré ?)."]);
    exit;
}

echo json_encode(['ok' => true, 'sent_to' => $user['email']]);
