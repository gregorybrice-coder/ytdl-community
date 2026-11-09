<?php
/** Initialise une installation neuve ; ne remplace jamais un compte existant. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/db.php';
$options = getopt('', ['name:', 'email:', 'url:']);
$email = $options['email'] ?? '';
$name = trim($options['name'] ?? '');
$url = rtrim($options['url'] ?? '', '/');
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $url)) {
    fwrite(STDERR, "Usage : php scripts/setup.php --name='Admin' --email='admin@example.org' --url='http://localhost:8080/ytdl'\n"); exit(1);
}
$db = ytdl_db();
if ((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
    fwrite(STDERR, "Installation déjà initialisée. Aucun compte ni réglage modifié.\n"); exit(1);
}
$library = YTDL_LIBRARY_ROOT;
if (!is_dir($library) && !mkdir($library, 0700, true)) { throw new RuntimeException('Bibliothèque inaccessible.'); }
if (realpath($library) === realpath(dirname(__DIR__)) || str_starts_with(realpath($library) . '/', realpath(dirname(__DIR__)) . '/')) {
    throw new RuntimeException('La bibliothèque doit être hors du site.');
}
$writePrivate = function (string $file, array $value): void {
    if (is_file($file)) { return; }
    $old = umask(0077);
    try { if (file_put_contents($file, "<?php\nreturn " . var_export($value, true) . ";\n", LOCK_EX) === false) { throw new RuntimeException('Écriture impossible.'); } }
    finally { umask($old); }
};
$writePrivate(YTDL_DATA_DIR . '/app-config.php', ['public_base_url' => $url]);
$keys = \Minishlink\WebPush\VAPID::createVapidKeys();
$writePrivate(YTDL_DATA_DIR . '/vapid-config.php', $keys + ['subject' => 'mailto:' . $email]);
if (file_put_contents($library . '/.ytdl-library-volume', '', LOCK_EX) === false) { throw new RuntimeException('Marqueur de bibliothèque inaccessible.'); }
$db->prepare('INSERT INTO users (name,email,status,is_admin) VALUES (?, ?, ?, ?)')->execute([$name,$email,'active',1]);
echo "Installation initialisée. Configurez le SMTP privé avant de demander un lien de connexion.\n";
