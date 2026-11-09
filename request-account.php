<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json');

ytdl_maintenance_gate(null, true);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim((string)($input['name'] ?? ''));
$email = trim((string)($input['email'] ?? ''));

if ($name === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Prénom et email requis.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Adresse email invalide.']);
    exit;
}

$pdo = ytdl_db();
ytdl_purge_stale_pending_accounts();

$check = $pdo->prepare('SELECT status FROM users WHERE email = :email');
$check->execute([':email' => $email]);
$existing = $check->fetchColumn();

if ($existing === 'active') {
    http_response_code(409);
    echo json_encode(['error' => 'Un compte actif existe déjà avec cet email.']);
    exit;
}
if ($existing === 'pending') {
    http_response_code(409);
    echo json_encode(['error' => 'Une demande est déjà en attente pour cet email.']);
    exit;
}

$avatars = glob(__DIR__ . '/avatars/avatar-*.jpg');
$defaultAvatar = $avatars ? basename($avatars[array_rand($avatars)]) : 'avatar-01.jpg';

$insert = $pdo->prepare('INSERT INTO users (name, email, avatar, status, is_admin) VALUES (:name, :email, :avatar, "pending", 0)');
$insert->execute([':name' => $name, ':email' => $email, ':avatar' => $defaultAvatar]);

// Prévient les admins actifs — sans bloquer la réponse si l'envoi échoue.
$admins = $pdo->query("SELECT * FROM users WHERE is_admin = 1 AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($admins as $admin) {
    ytdl_send_admin_new_request_email($admin, $name, $email);
}

echo json_encode(['ok' => true]);
