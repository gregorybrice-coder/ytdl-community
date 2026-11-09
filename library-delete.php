<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ytdl_json_error('Méthode non autorisée.', 405);
}

$id = (int)($_POST['id'] ?? 0);

// user_id dans la clause WHERE : empêche un compte de supprimer le fichier
// d'un autre en devinant/énumérant les id.
$stmt = ytdl_db()->prepare(
    'SELECT library_path FROM download_history WHERE id = :id AND user_id = :user_id'
);
$stmt->execute([':id' => $id, ':user_id' => $user['id']]);
$row = $stmt->fetch();

if ($row === false) {
    ytdl_json_error('Introuvable.', 404);
}

$path = $row['library_path'];

// Une autre ligne (n'importe quel compte — doublon de contenu consolidé
// dans Shared/, voir dedupe-check.php) pointe-t-elle encore vers EXACTEMENT
// ce même fichier ? Si oui, elle en a encore besoin : on ne supprime que
// CETTE ligne, jamais le fichier. Comptage à la demande plutôt qu'un
// compteur en base qui pourrait se désynchroniser.
$otherRefs = 0;
if ($path) {
    $refStmt = ytdl_db()->prepare('SELECT COUNT(*) FROM download_history WHERE library_path = :path AND id != :id');
    $refStmt->execute([':path' => $path, ':id' => $id]);
    $otherRefs = (int)$refStmt->fetchColumn();
}

if ($otherRefs > 0) {
    ytdl_db()->prepare('DELETE FROM download_history WHERE id = :id AND user_id = :user_id')
        ->execute([':id' => $id, ':user_id' => $user['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

// Dernière ligne sur ce chemin : quelqu'un y a-t-il encore accès par
// PARTAGE (pas par doublon de contenu, traité ci-dessus) — partage classique
// OU "emprunt" automatique (voir library-borrow.php et start.php), même
// table, même traitement. Si oui, la PROPRIÉTÉ lui est transférée au lieu
// d'effacer le fichier — le plus ancien destinataire devient le nouveau
// propriétaire, un choix déterministe simple ("premier arrivé").
$recipientStmt = ytdl_db()->prepare(
    'SELECT shared_with_user_id FROM library_shares WHERE history_id = :id ORDER BY created_at ASC LIMIT 1'
);
$recipientStmt->execute([':id' => $id]);
$newOwnerId = $recipientStmt->fetchColumn();

if ($newOwnerId !== false) {
    $newOwnerId = (int)$newOwnerId;
    // Le fichier partagé a normalement déjà rejoint Shared/ dès le premier
    // partage (voir library-share.php) — donc AUCUN renommage ici, juste un
    // changement de propriétaire. Le repli par renommage ne sert que pour
    // les lignes partagées avant ce mécanisme et pas encore migrées (voir
    // migrate-library-layout.php).
    $db = ytdl_db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE download_history SET user_id = :new_owner WHERE id = :id')
            ->execute([':new_owner' => $newOwnerId, ':id' => $id]);
        // Il devient propriétaire, plus un simple destinataire — les autres
        // lignes de partage éventuelles (s'il y avait plus de deux personnes
        // concernées) restent intactes : elles référencent history_id, pas
        // l'ancien propriétaire, library-play.php continue donc de leur
        // donner accès sans rien changer d'autre.
        $db->prepare('DELETE FROM library_shares WHERE history_id = :id AND shared_with_user_id = :new_owner')
            ->execute([':id' => $id, ':new_owner' => $newOwnerId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        ytdl_json_error('Échec du transfert.', 500);
    }
    echo json_encode(['ok' => true, 'transferred' => true]);
    exit;
}

// Personne d'autre n'y a accès (ni doublon, ni partage) : suppression
// définitive demandée depuis la page — un geste volontaire de l'utilisateur
// sur sa bibliothèque, pas une expiration silencieuse.
if ($path) {
    @unlink($path);
}

ytdl_db()->prepare('DELETE FROM download_history WHERE id = :id AND user_id = :user_id')
    ->execute([':id' => $id, ':user_id' => $user['id']]);

echo json_encode(['ok' => true]);
