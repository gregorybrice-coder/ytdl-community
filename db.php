<?php
require_once __DIR__ . '/config.php';
/** Connexion SQLite + schéma. La base vit hors du dossier servi par Apache. */

function ytdl_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dataDir = YTDL_DATA_DIR;
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0700, true);
    }

    $pdo = new PDO('sqlite:' . $dataDir . '/users.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    // WAL : une lecture en cours (un morceau qu'on diffuse, la bibliothèque
    // qu'on charge) ne bloque plus les écritures (partage, note, reprise de
    // lecture…), qui attendaient sinon jusqu'à échouer au bout de 30 s.
    // Réglage enregistré dans le fichier : sans effet après la première fois.
    $pdo->exec('PRAGMA busy_timeout = 10000');
    $pdo->exec('PRAGMA journal_mode = WAL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            avatar TEXT NOT NULL DEFAULT 'avatar-01.jpg',
            status TEXT NOT NULL DEFAULT 'pending', -- pending | active
            is_admin INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");

    // Reconnaissance du compte uniquement, indépendante des jetons de connexion.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS remembered_accounts (
            token_hash TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            expires_at TEXT NOT NULL
        )
    ");

    $pdo->exec("CREATE TABLE IF NOT EXISTS account_recovery_attempts (
        email_hash TEXT NOT NULL,
        ip_hash TEXT NOT NULL,
        attempted_at INTEGER NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_recovery_time ON account_recovery_attempts(attempted_at)');

    // Changement d'adresse email : la nouvelle adresse n'est pas écrite dans
    // users tant que le lien envoyé à l'ANCIENNE boîte n'a pas été ouvert.
    // Elle est donc stockée ici, avec le jeton — et jamais dans l'URL du lien,
    // pour qu'on ne puisse pas faire pointer un jeton vers une autre adresse.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_change_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            new_email TEXT NOT NULL,
            token TEXT NOT NULL UNIQUE,
            expires_at TEXT NOT NULL,
            consumed_at TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_change_user ON email_change_requests(user_id)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS download_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            device_id TEXT NOT NULL,
            user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            url TEXT NOT NULL,
            title TEXT,
            audio_only INTEGER NOT NULL DEFAULT 0,
            downloaded_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_download_history_device_url ON download_history(device_id, url, audio_only)');

    // Bibliothèque de lecture : thumbnail (affichage) + library_path (chemin
    // de la copie MP3 conservée, hors du dossier de job éphémère). Ajoutées
    // après coup à une table existante, d'où le PRAGMA au lieu d'un simple
    // CREATE TABLE — ALTER TABLE ADD COLUMN n'a pas d'équivalent "IF NOT EXISTS"
    // portable en SQLite.
    $existingCols = array_column($pdo->query('PRAGMA table_info(download_history)')->fetchAll(), 'name');
    if (!in_array('thumbnail', $existingCols, true)) {
        $pdo->exec('ALTER TABLE download_history ADD COLUMN thumbnail TEXT');
    }
    if (!in_array('library_path', $existingCols, true)) {
        $pdo->exec('ALTER TABLE download_history ADD COLUMN library_path TEXT');
    }
    // Chaîne YouTube d'origine : seule source d'artiste fiable quand le titre
    // n'en contient pas (« Like a Prayer », « Hungry Eyes »...). Renseignée à
    // partir des téléchargements suivant l'ajout de cette colonne ; NULL pour
    // les fichiers antérieurs, d'où le repli sur le seul titre à l'affichage.
    if (!in_array('uploader', $existingCols, true)) {
        $pdo->exec('ALTER TABLE download_history ADD COLUMN uploader TEXT');
    }
    // Durée réelle (secondes) du fichier archivé, mesurée via ffprobe — sert à
    // vérifier qu'un résultat de paroles synchronisées (lrclib.net) correspond
    // bien au même enregistrement avant de lui faire confiance : une simple
    // correspondance artiste/titre peut retomber sur une version différente
    // (live, radio edit...) au minutage totalement décalé. NULL pour les
    // fichiers antérieurs à cette colonne.
    if (!in_array('duration_seconds', $existingCols, true)) {
        $pdo->exec('ALTER TABLE download_history ADD COLUMN duration_seconds REAL');
    }
    // Niveau sonore mesuré du fichier (EBU R128) : loudness intégré en LUFS
    // et pic d'échantillon en dBFS. Les sources n'ont aucun niveau commun —
    // un clip YouTube est en moyenne 6 dB sous un MP3 extrait, et les clips
    // varient de près de 20 dB entre eux — d'où un gain de correction calculé
    // à la lecture (voir ytdl_normalization_gain_db). Mesuré une fois, jamais
    // recalculé : le fichier ne change pas. NULL tant qu'un fichier n'a pas
    // été mesuré, le gain vaut alors 0 dB (aucune correction).
    if (!in_array('loudness_lufs', $existingCols, true)) {
        $pdo->exec('ALTER TABLE download_history ADD COLUMN loudness_lufs REAL');
    }
    if (!in_array('peak_dbfs', $existingCols, true)) {
        $pdo->exec('ALTER TABLE download_history ADD COLUMN peak_dbfs REAL');
    }
    // Taille du fichier archivé, connue dès l'archivage (voir
    // ytdl_archive_job) — persistée pour servir de clé de dédoublonnage
    // physique (voir dedupe-check.php, ytdl_consolidate_duplicate_group) sans
    // rappeler filesize() sur chaque fichier à chaque comparaison. NULL pour
    // les lignes antérieures à cette colonne (jamais recalculée après coup —
    // voir migrate-library-layout.php pour le rattrapage ponctuel).
    if (!in_array('file_size_bytes', $existingCols, true)) {
        $pdo->exec('ALTER TABLE download_history ADD COLUMN file_size_bytes INTEGER');
    }
    // Catégorie orthogonale à audio_only : 'music' (par défaut — chanson ou
    // clip musical) ou 'other' (vidéo YouTube hors musique : documentaire,
    // tutoriel, vlog...). Un contenu "other" peut être audio_only=0 OU =1
    // (podcast par ex.) — les deux réglages restent indépendants côté
    // formulaire de téléchargement (voir capture.php, bouton "Hors musique").
    if (!in_array('category', $existingCols, true)) {
        $pdo->exec("ALTER TABLE download_history ADD COLUMN category TEXT NOT NULL DEFAULT 'music'");
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_download_history_library ON download_history(user_id, library_path)');
    // Index séparé, sans user_id en tête : sert à "toutes les lignes sur CE
    // chemin, tous comptes confondus" (dédoublonnage, comptage de références
    // à la suppression) — l'index composite ci-dessus est inefficace pour ça.
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_download_history_library_path ON download_history(library_path)');

    // Partage d'un fichier de bibliothèque avec d'autres comptes : jamais de
    // copie du fichier, juste un droit d'accès. ON DELETE CASCADE des deux
    // côtés — supprimer le morceau original ou le compte concerné retire
    // le partage automatiquement, sans code de nettoyage dédié.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS library_shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            history_id INTEGER NOT NULL REFERENCES download_history(id) ON DELETE CASCADE,
            shared_with_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            shared_by_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(history_id, shared_with_user_id)
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_library_shares_recipient ON library_shares(shared_with_user_id)');

    // Notation par étoiles (1-5), personnelle à chaque compte — un morceau
    // partagé peut être noté différemment par son propriétaire et par chacun
    // des destinataires, chacun avec ses propres goûts. Sert à pondérer la
    // lecture aléatoire (mini-lecteur) : plus la note est haute, plus le
    // morceau a de chances de repasser. ON DELETE CASCADE des deux côtés :
    // supprimer le morceau ou le compte efface la note sans code dédié.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS track_ratings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            history_id INTEGER NOT NULL REFERENCES download_history(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            rating INTEGER NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(history_id, user_id)
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_track_ratings_user ON track_ratings(user_id)');

    // Playlists : de simples regroupements ordonnés de fichiers déjà en
    // bibliothèque, audio et clips mélangés (le type se lit sur
    // download_history.audio_only). Aucune copie de fichier n'est faite.
    //
    // ON DELETE CASCADE des deux côtés : supprimer un morceau ou une playlist
    // nettoie les lignes correspondantes sans code dédié — c'est ce qui évite
    // les entrées fantômes pointant vers un fichier disparu.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS playlists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_playlists_user ON playlists(user_id)');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS playlist_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            playlist_id INTEGER NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
            history_id INTEGER NOT NULL REFERENCES download_history(id) ON DELETE CASCADE,
            position INTEGER NOT NULL DEFAULT 0,
            added_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(playlist_id, history_id)
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_playlist_items_playlist ON playlist_items(playlist_id, position)');

    // Abonnements aux notifications push (Web Push). Un compte peut avoir
    // plusieurs abonnements (un par appareil/installation PWA) — endpoint
    // unique par nature (propre à chaque installation), donc UNIQUE dessus
    // plutôt que sur user_id : un même compte connecté sur deux téléphones
    // reçoit la notification sur les deux.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            endpoint TEXT NOT NULL UNIQUE,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_push_subscriptions_user ON push_subscriptions(user_id)');

    // Reprise de lecture cross-device : une seule ligne par compte (clé
    // primaire = user_id, pas d'auto-increment), toujours écrasée par la
    // dernière position connue — c'est le dernier appareil à avoir joué de la
    // musique qui fait foi, pas un historique. device_id sert uniquement à
    // afficher/masquer intelligemment la bannière de reprise côté client
    // (pas de repli utile côté serveur).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS playback_state (
            user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
            history_id INTEGER NOT NULL REFERENCES download_history(id) ON DELETE CASCADE,
            position_seconds REAL NOT NULL DEFAULT 0,
            device_id TEXT NOT NULL,
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    // is_playing distingue "j'ai mis sur pause" de "je suis parti en pleine
    // écoute" : sur le MÊME appareil (rechargement forcé de la page), on
    // relance automatiquement dans le premier cas seulement — reprendre en
    // lecture un morceau qu'on avait volontairement arrêté serait surprenant.
    $playbackStateCols = array_column($pdo->query('PRAGMA table_info(playback_state)')->fetchAll(), 'name');
    if (!in_array('is_playing', $playbackStateCols, true)) {
        $pdo->exec('ALTER TABLE playback_state ADD COLUMN is_playing INTEGER NOT NULL DEFAULT 0');
    }

    // Code à 6 chiffres envoyé avec le lien de connexion : sur iPhone, l'appli
    // ajoutée à l'écran d'accueil a ses propres cookies, et le lien de l'email
    // s'ouvre dans le navigateur — on tape alors le code dans l'appli
    // (login-code.php). code_request : clé opaque remise à l'appareil demandeur.
    $loginTokenCols = array_column($pdo->query('PRAGMA table_info(login_tokens)')->fetchAll(), 'name');
    foreach (['code_request TEXT', 'code_hash TEXT', 'code_expires_at TEXT', 'code_attempts INTEGER NOT NULL DEFAULT 0'] as $col) {
        if (!in_array(strtok($col, ' '), $loginTokenCols, true)) $pdo->exec('ALTER TABLE login_tokens ADD COLUMN ' . $col);
    }

    // Fiches artistes archivées (voir ytdl_archive_artist dans lib.php) :
    // l'article Wikipédia complet est conservé en base pour survivre à la
    // disparition de la page. found = 0 : rien trouvé (on réessaie après
    // 30 jours) ; link_ok : le lien Wikipédia répondait à la dernière
    // vérification (sinon l'affichage passe sur la fiche archivée).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS artists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name_key TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            found INTEGER NOT NULL DEFAULT 0,
            lang TEXT,
            wiki_title TEXT,
            wiki_url TEXT,
            thumbnail_url TEXT,
            photo_path TEXT,
            intro TEXT,
            full_text TEXT,
            fetched_at TEXT NOT NULL DEFAULT (datetime('now')),
            link_checked_at TEXT NOT NULL DEFAULT (datetime('now')),
            link_ok INTEGER NOT NULL DEFAULT 1
        )
    ");
    // locked = 1 : fiche écartée à la main (homonyme sans rapport), plus
    // jamais recherchée automatiquement (voir archive-artists.php --bloquer).
    $artistCols = array_column($pdo->query('PRAGMA table_info(artists)')->fetchAll(), 'name');
    if (!in_array('locked', $artistCols, true)) {
        $pdo->exec('ALTER TABLE artists ADD COLUMN locked INTEGER NOT NULL DEFAULT 0');
    }

    // Certaines installations historiques avaient ajouté ces colonnes manuellement.
    $quotaCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('quota_bytes', $quotaCols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN quota_bytes INTEGER');
    }
    $resumeCols = array_column($pdo->query('PRAGMA table_info(playback_state)')->fetchAll(), 'name');
    if (!in_array('playlist_id', $resumeCols, true)) {
        $pdo->exec('ALTER TABLE playback_state ADD COLUMN playlist_id INTEGER REFERENCES playlists(id) ON DELETE SET NULL');
    }

    // Notifications "nouveauté" ponctuelles (une par fonctionnalité annoncée,
    // pas un fil au sens messagerie). Un compte peut en avoir PLUSIEURS en
    // attente à la fois (ex. deux fonctionnalités ajoutées avant sa prochaine
    // visite) : users.notification_ids les liste sous forme de nombres
    // séparés par des virgules ("" = aucune), retirés UN PAR UN — pas tous
    // d'un coup — à mesure que l'utilisateur voit chaque effet néon qui lui
    // correspond (voir notification-ack.php, appelé avec l'id précis acquitté,
    // et pendingNotifications côté JS dans capture.php). `type` pilote côté
    // client quel effet visuel jouer ; title/message restent disponibles pour
    // un futur centre de notifications textuel.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            title TEXT NOT NULL,
            message TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )
    ");
    $userCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('notification_ids', $userCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN notification_ids TEXT NOT NULL DEFAULT ''");
        // Reprend l'ancienne colonne à notification unique (0 = aucune) dans
        // la nouvelle liste, puis la retire — plus personne ne doit s'en
        // servir, la garder aurait juste permis de la remplir par erreur.
        if (in_array('notification_id', $userCols, true)) {
            $pdo->exec("UPDATE users SET notification_ids = CAST(notification_id AS TEXT) WHERE notification_id != 0");
            $pdo->exec('ALTER TABLE users DROP COLUMN notification_id');
        }
    }

    // Clés d'accès (WebAuthn) : connexion par empreinte / visage / code de
    // l'appareil. Seule la clé publique est stockée ; la biométrie ne quitte
    // jamais le téléphone. Voir webauthn.php.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            credential_id TEXT NOT NULL UNIQUE,
            public_key TEXT NOT NULL,
            sign_count INTEGER NOT NULL DEFAULT 0,
            label TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            last_used_at TEXT
        )
    ");

    // Apparence choisie (sombre, clair, ambre, kawaii, sillon, orbite,
    // platine) : gardée côté serveur pour suivre le compte d'un appareil à
    // l'autre. NULL = jamais choisie ici, chaque appareil garde alors son
    // propre réglage local (comportement d'avant).
    if (!in_array('skin', $userCols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN skin TEXT');
    }

    // Ajoute UNE notification à TOUS les comptes déjà existants (sans
    // doublon si elle y est déjà) — utilisé pour les annonces ponctuelles
    // ci-dessous. Un compte créé après coup n'a pas à être notifié d'une
    // fonctionnalité présente depuis son inscription, donc jamais appelé
    // pour les comptes futurs, seulement au moment de l'annonce.
    $notifyAllUsers = function (PDO $pdo, int $notificationId): void {
        $rows = $pdo->query('SELECT id, notification_ids FROM users')->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare('UPDATE users SET notification_ids = :ids WHERE id = :id');
        foreach ($rows as $row) {
            $ids = array_filter(explode(',', $row['notification_ids']), fn($v) => $v !== '');
            if (in_array((string)$notificationId, $ids, true)) continue;
            $ids[] = (string)$notificationId;
            $update->execute([':ids' => implode(',', $ids), ':id' => $row['id']]);
        }
    };

    // Annonce ponctuelle des skins (Sombre/Clair/Ambre/Kawaii) : posée une
    // seule fois. Le test sur l'existence du type sert de garde-fou pour ne
    // jamais la recréer / réassigner à chaque démarrage.
    $skinsNotifExists = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'skins'")->fetchColumn();
    if ($skinsNotifExists === 0) {
        $pdo->exec("
            INSERT INTO notifications (type, title, message) VALUES (
                'skins',
                'Nouveaux styles disponibles',
                'Personnalise l''apparence de ytdl avec de nouveaux skins (Sombre, Clair, Ambre, Kawaii) depuis Mon profil > Apparence.'
            )
        ");
        $notifyAllUsers($pdo, (int)$pdo->lastInsertId());
    }

    return $pdo;
}

/**
 * Associe au jeton un code à 6 chiffres (15 min, 5 essais) et une clé de
 * demande opaque. Renvoie [clé de demande, code] ; le code part dans l'email,
 * la clé revient à l'appareil qui a demandé le lien.
 */
function ytdl_attach_login_code(string $token): array {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $request = bin2hex(random_bytes(16));
    ytdl_db()->prepare("UPDATE login_tokens SET code_request = :request, code_hash = :hash,
            code_expires_at = datetime('now', '+15 minutes'), code_attempts = 0 WHERE token = :token")
        ->execute([':request' => $request, ':hash' => hash('sha256', $token . '|' . $code), ':token' => $token]);
    return [$request, $code];
}

/** Purge les tokens expirés (>24h) à chaque appel — pas besoin de cron pour un aussi petit volume. */
function ytdl_purge_expired_tokens(): void {
    ytdl_db()->exec("DELETE FROM login_tokens WHERE expires_at < datetime('now')");
    // Même logique pour les demandes de changement d'email : une demande
    // périmée ou déjà utilisée n'a plus aucune raison d'être conservée.
    ytdl_db()->exec("DELETE FROM email_change_requests WHERE expires_at < datetime('now') OR consumed_at IS NOT NULL");
}
