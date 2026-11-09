<?php
require_once __DIR__ . '/auth.php';
$user = ytdl_require_login();

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$avatar = basename((string)($input['avatar'] ?? ''));

if (!preg_match('/^avatar-\d{2}\.jpg$/', $avatar) || !is_file(__DIR__ . '/avatars/' . $avatar)) {
    http_response_code(400);
    echo json_encode(['error' => 'Avatar invalide.']);
    exit;
}

$stmt = ytdl_db()->prepare('UPDATE users SET avatar = :avatar WHERE id = :id');
$stmt->execute([':avatar' => $avatar, ':id' => $user['id']]);

echo json_encode(['ok' => true]);
