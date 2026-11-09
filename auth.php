<?php
require_once __DIR__ . '/db.php';
// lib.php fournit ytdl_maintenance_gate(), appelée par ytdl_require_login() :
// les pages HTML ne chargeaient jusqu'ici que db.php, contrairement aux
// endpoints JSON qui incluent lib.php eux-mêmes.
require_once __DIR__ . '/lib.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Options communes, compatibles avec /ytdl/ et une installation à la racine. */
function ytdl_cookie_options(int $expires): array {
    $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return [
        'expires' => $expires,
        'path' => rtrim($path, '/') . '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** Ce cookie choisit seulement l'avatar : il ne donne jamais accès au compte. */
function ytdl_remembered_account(): ?array {
    $token = $_COOKIE['ytdl_account'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
    $stmt = ytdl_db()->prepare("SELECT u.* FROM users u
        JOIN remembered_accounts r ON r.user_id = u.id
        WHERE r.token_hash = :hash AND r.expires_at >= datetime('now') AND u.status = 'active'");
    $stmt->execute([':hash' => hash('sha256', $token)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ytdl_remember_account(array $user): void {
    $known = ytdl_remembered_account();
    if ($known && (int)$known['id'] === (int)$user['id']) return;
    $db = ytdl_db();
    $old = $_COOKIE['ytdl_account'] ?? '';
    if (is_string($old) && preg_match('/^[a-f0-9]{64}$/D', $old)) {
        $db->prepare('DELETE FROM remembered_accounts WHERE token_hash = ?')->execute([hash('sha256', $old)]);
    }
    $db->exec("DELETE FROM remembered_accounts WHERE expires_at < datetime('now')");
    $token = bin2hex(random_bytes(32));
    $expires = time() + 365 * 86400;
    $db->prepare('INSERT INTO remembered_accounts (token_hash, user_id, expires_at) VALUES (?, ?, ?)')
        ->execute([hash('sha256', $token), $user['id'], gmdate('Y-m-d H:i:s', $expires)]);
    setcookie('ytdl_account', $token, ytdl_cookie_options($expires));
    $_COOKIE['ytdl_account'] = $token;
}

function ytdl_welcome_account(): ?array {
    return ytdl_remembered_account() ?? (ytdl_db()->query("SELECT * FROM users
        WHERE status = 'active' AND is_admin = 1 ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null);
}

function ytdl_current_user(): ?array {
    if (!empty($_SESSION['user_id'])) {
        $stmt = ytdl_db()->prepare('SELECT * FROM users WHERE id = :id AND status = "active"');
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            ytdl_remember_account($user);
            return $user;
        }
    }

    // Pas de session active : reconnaît automatiquement l'appareil via le
    // cookie posé lors d'une connexion précédente (voir verify.php), sans
    // repasser par le choix d'avatar ni un nouvel email.
    $cookieToken = $_COOKIE['ytdl_token'] ?? '';
    if ($cookieToken !== '') {
        $stmt = ytdl_db()->prepare("
            SELECT u.* FROM users u
            JOIN login_tokens t ON t.user_id = u.id
            WHERE t.token = :token AND t.expires_at >= datetime('now') AND u.status = 'active'
        ");
        $stmt->execute([':token' => $cookieToken]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            ytdl_remember_account($user);
            return $user;
        }
    }

    return null;
}

/**
 * Redirige vers la page de connexion si personne n'est authentifié. À appeler en tout début des pages/endpoints protégés.
 *
 * La session est ensuite refermée (lecture seule, $_SESSION reste lisible) :
 * PHP la verrouille tant qu'elle est ouverte, et une requête longue (lecture
 * d'un clip, paroles, bio, recherche YouTube…) bloquait alors toutes les
 * autres requêtes du compte, jusqu'à les faire échouer au bout de 30 s.
 * $keepSession = true pour les rares pages qui écrivent encore dans $_SESSION.
 */
function ytdl_require_login(bool $keepSession = false): array {
    $user = ytdl_current_user();
    $isJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '.php') && !empty($_GET['job'] ?? $_GET['url'] ?? '');

    if (!$user) {
        // Maintenance : on coupe avant même de renvoyer vers la page de
        // connexion, sinon un visiteur déconnecté verrait le formulaire de
        // login d'un site censé être fermé.
        ytdl_maintenance_gate(null, $isJson);
        if ($isJson) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Session expirée, reconnecte-toi.']);
            exit;
        }
        header('Location: index.php');
        exit;
    }

    // Point de passage de tous les endpoints protégés : un seul appel suffit
    // à couvrir capture, title, start, progress, file et share.
    ytdl_maintenance_gate($user, $isJson);
    if (!$keepSession && session_status() === PHP_SESSION_ACTIVE) session_write_close();
    return $user;
}

function ytdl_require_admin(): array {
    $user = ytdl_require_login();
    if ((int)$user['is_admin'] !== 1) {
        http_response_code(403);
        echo 'Accès réservé à l\'administrateur.';
        exit;
    }
    return $user;
}
