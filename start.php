<?php
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/download-start.php';
ytdl_require_login();

$url = ytdl_clean_url($_GET['url'] ?? '');
ytdl_validate_url($url);

try {
    $r = ytdl_start_download((int)($_SESSION['user_id'] ?? 0), $url, [
        'audioOnly'   => ($_GET['audio_only'] ?? '0') === '1',
        'deviceId'    => (string)($_GET['device_id'] ?? ''),
        'category'    => (string)($_GET['category'] ?? 'music'),
        'titlePrefix' => (string)($_GET['title_prefix'] ?? ''),
        'force'       => ($_GET['force'] ?? '0') === '1',
    ]);
} catch (RuntimeException $e) {
    ytdl_json_error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
}

header('Content-Type: application/json');
if ($r['status'] === 'duplicate') {
    echo json_encode(['alreadyDownloaded' => true, 'title' => $r['title'], 'reason' => $r['reason']]);
} else {
    echo json_encode(['jobId' => $r['jobId'], 'title' => $r['title']]);
}
