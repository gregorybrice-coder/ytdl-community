<?php
/**
 * Confirmation d'un changement d'adresse email.
 *
 * Le lien arrive dans l'ANCIENNE boîte et sera souvent ouvert depuis un autre
 * appareil que celui où la demande a été faite : on n'exige donc pas de
 * session, la possession du jeton fait foi. Le jeton porte à lui seul le
 * couple (compte, nouvelle adresse), rien n'est repris de l'URL.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

ytdl_maintenance_gate(ytdl_current_user());

function ytdl_email_change_page(string $title, string $message, bool $isError, string $linkLabel = 'Retour à ytdl', string $href = 'index.php'): never {
    if ($isError) http_response_code(400);
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>';
    echo '<style>body{font-family:system-ui,sans-serif;background:#0f1115;color:#e6e6e6;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}';
    echo '.box{max-width:440px;text-align:center;padding:32px;} a{color:#5cc8ff;} p{line-height:1.55;color:#a7adba;} strong{color:#e6e6e6;}</style></head><body>';
    echo '<div class="box"><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1><p>' . $message . '</p>';
    echo '<p><a href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . htmlspecialchars($linkLabel, ENT_QUOTES) . '</a></p></div></body></html>';
    exit;
}

$token = $_GET['token'] ?? '';
if ($token === '') {
    ytdl_email_change_page('Lien invalide', 'Aucun jeton fourni.', true);
}

$pdo = ytdl_db();

$stmt = $pdo->prepare(
    "SELECT * FROM email_change_requests
     WHERE token = :token AND consumed_at IS NULL AND expires_at >= datetime('now')"
);
$stmt->execute([':token' => $token]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    ytdl_email_change_page(
        'Lien invalide ou expiré',
        'Ce lien a déjà servi, a été annulé par une demande plus récente, ou a dépassé sa validité de 24h. Tu peux relancer la démarche depuis ytdl, menu <strong>Changer d\'email</strong>.',
        true
    );
}

$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND status = 'active'");
$userStmt->execute([':id' => $request['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    ytdl_email_change_page('Compte introuvable', "Ce compte n'est plus actif.", true);
}

// Re-contrôle au moment d'appliquer : l'adresse a pu être prise entre-temps
// par un autre compte (demande simultanée, création de compte...).
$taken = $pdo->prepare('SELECT COUNT(*) FROM users WHERE lower(email) = lower(:email) AND id <> :id');
$taken->execute([':email' => $request['new_email'], ':id' => $user['id']]);
if ((int)$taken->fetchColumn() > 0) {
    $cleanup = $pdo->prepare('DELETE FROM email_change_requests WHERE id = :id');
    $cleanup->execute([':id' => $request['id']]);
    ytdl_email_change_page(
        'Adresse déjà prise',
        'Entre ta demande et maintenant, <strong>' . htmlspecialchars($request['new_email'], ENT_QUOTES) . '</strong> a été rattachée à un autre compte. Ton adresse actuelle est conservée.',
        true
    );
}

$oldEmail = $user['email'];

// Le jeton est marqué consommé dans la même transaction que la mise à jour :
// pas de fenêtre où l'adresse changerait sans que le lien soit neutralisé, ni
// l'inverse.
$pdo->beginTransaction();
try {
    $update = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id');
    $update->execute([':email' => $request['new_email'], ':id' => $user['id']]);

    $consume = $pdo->prepare("UPDATE email_change_requests SET consumed_at = datetime('now') WHERE id = :id");
    $consume->execute([':id' => $request['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('ytdl changement email : ' . $e->getMessage());
    ytdl_email_change_page('Changement impossible', "Une erreur est survenue, ton adresse n'a pas été modifiée.", true);
}

// Confirmation vers la nouvelle boîte — sans bloquer la page si l'envoi rate,
// le changement, lui, est déjà effectif.
ytdl_send_email_changed_notice($user, $request['new_email'], $oldEmail);

ytdl_email_change_page(
    'Adresse email mise à jour',
    'Ton compte utilise désormais <strong>' . htmlspecialchars($request['new_email'], ENT_QUOTES) . '</strong>. '
        . 'Tes prochains liens de connexion arriveront dans cette boîte.',
    false,
    'Aller à ytdl'
);
