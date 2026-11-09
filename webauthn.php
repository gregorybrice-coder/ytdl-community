<?php
/**
 * Connexion par empreinte / visage / code de l'appareil (WebAuthn, « clés
 * d'accès »). Le téléphone vérifie la biométrie lui-même et ne renvoie
 * qu'une signature : le serveur ne garde que la clé publique
 * (table webauthn_credentials, voir db.php).
 *
 * Actions (?action=…) :
 *   register-options  GET   compte connecté : paramètres de création
 *   register          POST  compte connecté : enregistre la clé de l'appareil
 *   login-options     GET   sans session    : paramètres de connexion
 *   login             POST  sans session    : vérifie la signature et connecte
 *   list              GET   compte connecté : clés enregistrées
 *   delete            POST  compte connecté : retire une clé
 *
 * Vérifications cryptographiques : bibliothèque lbuchs/webauthn (Composer).
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;

header('Content-Type: application/json');
header('Cache-Control: no-store');

$action = (string)($_GET['action'] ?? '');

// L'identifiant de la « partie de confiance » est le nom d'hôte du site
// (sans port) : une clé créée sur music.example.org ne sert qu'ici
// (celles de l'ancien old.example.org sont à réenregistrer).
$rpId = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
// Format d'attestation « none » : on ne demande pas au téléphone de prouver
// sa marque, seulement de posséder la clé. Évite les refus de certains
// appareils sans rien enlever à la sécurité de la connexion. Dernier
// argument : données binaires transmises en base64url (lisibles par
// ui/passkey.js).
$webAuthn = new WebAuthn('ytdl+', $rpId, ['none'], true);

function ytdl_webauthn_input(): array {
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function ytdl_b64url_decode(string $value): string {
    $value = strtr($value, '-_', '+/');
    return (string)base64_decode($value . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
}

function ytdl_b64url_encode(string $binary): string {
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

// Nom lisible de l'appareil, pour la liste du profil.
function ytdl_device_label(): string {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $os = str_contains($ua, 'Android') ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iPhone / iPad'
        : (str_contains($ua, 'Mac OS') ? 'Mac' : (str_contains($ua, 'Windows') ? 'Windows' : 'Appareil')));
    $browser = str_contains($ua, 'Edg/') ? 'Edge' : (str_contains($ua, 'Chrome') ? 'Chrome'
        : (str_contains($ua, 'Firefox') ? 'Firefox' : (str_contains($ua, 'Safari') ? 'Safari' : '')));
    return trim($os . ($browser ? ' · ' . $browser : ''));
}

try {
    switch ($action) {

        case 'register-options': {
            $user = ytdl_require_login(true); // écrit le défi en session
            $stmt = ytdl_db()->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id = :uid');
            $stmt->execute([':uid' => $user['id']]);
            $exclude = array_map('ytdl_b64url_decode', $stmt->fetchAll(PDO::FETCH_COLUMN));
            // Authentificateur de l'appareil lui-même (empreinte, visage,
            // code), vérification de l'utilisateur obligatoire, clé
            // « découvrable » si possible : la connexion n'a alors même pas
            // besoin de savoir qui se connecte.
            $args = $webAuthn->getCreateArgs('ytdl-user-' . $user['id'], (string)$user['email'], (string)$user['name'],
                60, 'preferred', true, false, $exclude);
            $_SESSION['webauthn_register_challenge'] = $webAuthn->getChallenge()->getBinaryString();
            echo json_encode($args);
            break;
        }

        case 'register': {
            $user = ytdl_require_login(true); // consomme le défi en session
            $challenge = $_SESSION['webauthn_register_challenge'] ?? null;
            unset($_SESSION['webauthn_register_challenge']);
            if (!$challenge) ytdl_json_error('Demande expirée, recommence.', 400);
            $in = ytdl_webauthn_input();
            $data = $webAuthn->processCreate(
                ytdl_b64url_decode((string)($in['clientDataJSON'] ?? '')),
                ytdl_b64url_decode((string)($in['attestationObject'] ?? '')),
                $challenge, true, true, false
            );
            $credentialId = ytdl_b64url_encode($data->credentialId instanceof ByteBuffer ? $data->credentialId->getBinaryString() : (string)$data->credentialId);
            ytdl_db()->prepare('INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, label)
                VALUES (:uid, :cid, :pk, :cnt, :label)')
                ->execute([
                    ':uid' => $user['id'], ':cid' => $credentialId, ':pk' => $data->credentialPublicKey,
                    ':cnt' => (int)$data->signatureCounter, ':label' => ytdl_device_label(),
                ]);
            echo json_encode(['ok' => true, 'credential_id' => $credentialId]);
            break;
        }

        case 'login-options': {
            ytdl_maintenance_gate(null, true);
            // Indice facultatif envoyé par l'appareil (la clé qu'il a créée) :
            // évite au téléphone de proposer d'autres comptes.
            $ids = [];
            $hint = (string)($_GET['credential'] ?? '');
            if ($hint !== '' && preg_match('/^[A-Za-z0-9_-]{16,512}$/', $hint)) $ids[] = ytdl_b64url_decode($hint);
            $args = $webAuthn->getGetArgs($ids, 60, false, false, false, true, true, true);
            $_SESSION['webauthn_login_challenge'] = $webAuthn->getChallenge()->getBinaryString();
            echo json_encode($args);
            break;
        }

        case 'login': {
            ytdl_maintenance_gate(null, true);
            $challenge = $_SESSION['webauthn_login_challenge'] ?? null;
            unset($_SESSION['webauthn_login_challenge']);
            if (!$challenge) ytdl_json_error('Demande expirée, recommence.', 400);
            $in = ytdl_webauthn_input();
            $credentialId = (string)($in['id'] ?? '');
            $stmt = ytdl_db()->prepare("SELECT c.*, u.status FROM webauthn_credentials c JOIN users u ON u.id = c.user_id
                WHERE c.credential_id = :cid");
            $stmt->execute([':cid' => $credentialId]);
            $cred = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cred) ytdl_json_error("Cette empreinte n'est plus reconnue : reconnecte-toi par e-mail puis réactive-la.", 404);
            if ($cred['status'] !== 'active') ytdl_json_error("Ce compte n'est plus actif.", 403);

            $webAuthn->processGet(
                ytdl_b64url_decode((string)($in['clientDataJSON'] ?? '')),
                ytdl_b64url_decode((string)($in['authenticatorData'] ?? '')),
                ytdl_b64url_decode((string)($in['signature'] ?? '')),
                $cred['public_key'], $challenge, (int)$cred['sign_count'], true, true
            );

            $db = ytdl_db();
            $db->prepare("UPDATE webauthn_credentials SET sign_count = :cnt, last_used_at = datetime('now') WHERE id = :id")
                ->execute([':cnt' => (int)$webAuthn->getSignatureCounter(), ':id' => $cred['id']]);

            $userStmt = $db->prepare("SELECT * FROM users WHERE id = :id AND status = 'active'");
            $userStmt->execute([':id' => $cred['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            // Même effet qu'un lien de connexion avec « Rester connecté » :
            // jeton longue durée en cookie, compte mémorisé, session neuve.
            $token = bin2hex(random_bytes(32));
            $expiresAt = (new DateTime('+10 years'))->format('Y-m-d H:i:s');
            $db->prepare('INSERT INTO login_tokens (user_id, token, expires_at) VALUES (:uid, :token, :exp)')
                ->execute([':uid' => $user['id'], ':token' => $token, ':exp' => $expiresAt]);
            setcookie('ytdl_token', $token, ytdl_cookie_options(strtotime($expiresAt . ' UTC')));
            ytdl_remember_account($user);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];

            echo json_encode(['ok' => true, 'redirect' => 'capture.php']);
            break;
        }

        case 'list': {
            $user = ytdl_require_login();
            $stmt = ytdl_db()->prepare('SELECT credential_id, label, created_at, last_used_at FROM webauthn_credentials WHERE user_id = :uid ORDER BY id');
            $stmt->execute([':uid' => $user['id']]);
            echo json_encode(['credentials' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        }

        case 'delete': {
            $user = ytdl_require_login();
            $in = ytdl_webauthn_input();
            ytdl_db()->prepare('DELETE FROM webauthn_credentials WHERE user_id = :uid AND credential_id = :cid')
                ->execute([':uid' => $user['id'], ':cid' => (string)($in['credential_id'] ?? '')]);
            echo json_encode(['ok' => true]);
            break;
        }

        default:
            ytdl_json_error('Action inconnue.', 400);
    }
} catch (\lbuchs\WebAuthn\WebAuthnException $e) {
    ytdl_json_error('Vérification refusée : ' . $e->getMessage(), 400);
}
