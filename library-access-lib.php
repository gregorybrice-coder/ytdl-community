<?php
/**
 * Accès en lecture seule à la bibliothèque d'un autre compte (table
 * library_access, voir db.php). Un seul endroit décide qui peut voir quoi :
 * library-play.php, library-rate.php, playback-state.php et library-of.php
 * passent tous par ici.
 */
require_once __DIR__ . '/db.php';

/** Le demandeur a-t-il une autorisation active sur la bibliothèque de ce propriétaire ? */
function ytdl_viewer_has_library_access(int $viewerId, int $ownerId): bool {
    if ($viewerId === $ownerId) return false;
    $stmt = ytdl_db()->prepare(
        "SELECT 1 FROM library_access WHERE owner_user_id = :owner AND viewer_user_id = :viewer AND status = 'approved'"
    );
    $stmt->execute([':owner' => $ownerId, ':viewer' => $viewerId]);
    return $stmt->fetchColumn() !== false;
}

/** Nombre de titres visibles dans la bibliothèque d'un compte. */
function ytdl_library_track_count(int $ownerId): int {
    $stmt = ytdl_db()->prepare(
        "SELECT COUNT(*) FROM download_history WHERE user_id = :id AND library_path IS NOT NULL AND library_path <> ''"
    );
    $stmt->execute([':id' => $ownerId]);
    return (int)$stmt->fetchColumn();
}

/** Corps commun des e-mails envoyés par cette fonctionnalité (même SMTP que les liens de connexion). */
function ytdl_send_plain_email(string $toEmail, string $subject, string $body): bool {
    require_once __DIR__ . '/mailer.php';
    $smtp = ytdl_smtp_config();
    if ($smtp) {
        return ytdl_send_via_smtp($smtp, $toEmail, $subject, $body);
    }
    $fromDomain = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $headers = "From: ytdl <no-reply@{$fromDomain}>\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
    return mail($toEmail, $subject, $body, $headers);
}

/** Prévient le propriétaire (push + e-mail) qu'un compte demande l'accès à sa bibliothèque. */
function ytdl_notify_library_access_request(array $owner, string $viewerName): void {
    ytdl_send_push((int)$owner['id'], 'Demande d’accès', $viewerName . ' demande l’accès à ta bibliothèque.', ['url' => '/ytdl/capture.php']);
    require_once __DIR__ . '/mailer.php';
    ytdl_send_plain_email(
        $owner['email'],
        'ytdl — ' . $viewerName . ' demande l’accès à ta bibliothèque',
        "Bonjour {$owner['name']},\n\n"
        . "{$viewerName} demande à consulter ta bibliothèque, en lecture seule : elle la verra comme une playlist à ton nom, "
        . "pourra l'écouter et en importer des titres (sans copier tes fichiers).\n\n"
        . "Pour accepter ou refuser, ouvre ytdl puis ton profil, rubrique « Bibliothèques » :\n"
        . ytdl_base_url() . "/\n"
    );
}

/** Prévient le demandeur que le propriétaire a accepté. */
function ytdl_notify_library_access_granted(array $viewer, string $ownerName): void {
    ytdl_send_push((int)$viewer['id'], 'Accès accordé', $ownerName . ' t’a ouvert sa bibliothèque : elle est dans tes playlists.', ['url' => '/ytdl/capture.php']);
    require_once __DIR__ . '/mailer.php';
    ytdl_send_plain_email(
        $viewer['email'],
        'ytdl — ' . $ownerName . ' t’a ouvert sa bibliothèque',
        "Bonjour {$viewer['name']},\n\n"
        . "{$ownerName} a accepté ta demande : sa bibliothèque apparaît maintenant dans tes playlists, sous son prénom.\n\n"
        . ytdl_base_url() . "/\n"
    );
}
