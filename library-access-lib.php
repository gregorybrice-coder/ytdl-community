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

/** Titres visibles d'un compte : total, clips (vidéos hors « hors musique ») et hors musique. */
function ytdl_library_track_counts(int $ownerId): array {
    $stmt = ytdl_db()->prepare(
        "SELECT COUNT(*) AS n,
                SUM(audio_only = 0 AND category <> 'other') AS clips,
                SUM(category = 'other') AS other
         FROM download_history WHERE user_id = :id AND library_path IS NOT NULL AND library_path <> ''"
    );
    $stmt->execute([':id' => $ownerId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return ['count' => (int)$r['n'], 'clips' => (int)$r['clips'], 'other' => (int)$r['other']];
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


/** Durée pendant laquelle le titre qu'un compte partage reste lisible par les autres (secondes). */
const YTDL_LIVE_WINDOW = 1800;

/**
 * Ce titre est-il celui qu'un compte écoute et PARTAGE en ce moment ?
 * Seul ce titre devient lisible (jamais téléchargeable) pour les autres comptes,
 * pour qu'ils l'écoutent en même temps (listen-live.php).
 */
function ytdl_track_is_live_shared(int $historyId): bool {
    $stmt = ytdl_db()->prepare(
        "SELECT 1 FROM playback_state ps JOIN users u ON u.id = ps.user_id
         WHERE ps.history_id = :id AND u.share_listening = 1 AND u.status = 'active' AND ps.updated_ts > :since LIMIT 1"
    );
    $stmt->execute([':id' => $historyId, ':since' => microtime(true) - YTDL_LIVE_WINDOW]);
    return $stmt->fetchColumn() !== false;
}
