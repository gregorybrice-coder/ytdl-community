<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

$admin = ytdl_require_admin();
$pdo = ytdl_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'approve' && $userId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND status = 'pending'");
        $stmt->execute([':id' => $userId]);
        $pendingUser = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pendingUser) {
            $pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id")->execute([':id' => $userId]);
            ytdl_send_account_approved_email($pendingUser);
        }
    } elseif ($action === 'reject' && $userId > 0) {
        $pdo->prepare("DELETE FROM users WHERE id = :id AND status = 'pending'")->execute([':id' => $userId]);
    }

    header('Location: admin.php');
    exit;
}

ytdl_purge_stale_pending_accounts();
$pending = $pdo->query("SELECT * FROM users WHERE status = 'pending' ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
$active = $pdo->query("SELECT * FROM users WHERE status = 'active' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ytdl — Administration</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Space+Grotesk:wght@500;600;700&display=swap');
  :root {
    --bg: #050816; --panel: rgba(12,20,43,.82); --border: rgba(132,173,255,.22);
    --text: #edf5ff; --text-dim: #94a7c7;
    --accent: #49e8ff; --accent-2: #9e72ff; --error: #ff718f; --success: #56edb1;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; background:radial-gradient(circle at 15% 0%,rgba(73,232,255,.15),transparent 30%),radial-gradient(circle at 90% 90%,rgba(158,114,255,.15),transparent 35%),var(--bg);
    color: var(--text); font-family:"Space Grotesk",-apple-system,sans-serif;
    padding: 40px 20px;
  }
  .wrap { max-width: 680px; margin: 0 auto; }
  h1 {
    font-size: 2rem; letter-spacing:-.06em; margin: 0 0 4px;
    background: linear-gradient(100deg, var(--accent), var(--accent-2));
    -webkit-background-clip: text; background-clip: text; color: transparent;
  }
  .back { font-size: 0.82rem; color: var(--text-dim); text-decoration: none; }
  .back:hover { color: var(--accent); }
  h2 { font-size: 1rem; margin: 32px 0 12px; color: var(--text-dim); font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
  .card {
    background: var(--panel); border: 1px solid var(--border); border-radius: 16px;
    padding: 14px 16px; margin-bottom: 10px; display: flex; align-items: center; gap: 12px;
  }
  .card img { width: 40px; height: 60px; object-fit: cover; border-radius: 8px; flex-shrink: 0; box-shadow:0 0 0 1px rgba(73,232,255,.2); }
  .card .info { flex: 1; min-width: 0; }
  .card .name { font-weight: 600; font-size: 0.9rem; }
  .card .email { font-size: 0.78rem; color: var(--text-dim); }
  .card .badge { font-size: 0.7rem; padding: 3px 8px; border-radius: 999px; background: rgba(92,200,255,0.15); color: var(--accent); }
  .actions { display: flex; gap: 8px; }
  button {
    border: none; border-radius: 8px; padding: 8px 14px; font-size: 0.8rem; font-weight: 600; cursor: pointer;
  }
  button.approve { background: var(--success); color: #0a0c10; }
  button.reject { background: var(--error); color: #0a0c10; }
  .empty { color: var(--text-dim); font-size: 0.85rem; font-style: italic; }
</style>
</head>
<body>
  <div class="wrap">
    <a class="back" href="capture.php">&larr; Retour à l'application</a>
    <h1>Administration ytdl</h1>

    <h2>Demandes en attente</h2>
    <?php if (empty($pending)): ?>
      <p class="empty">Aucune demande en attente.</p>
    <?php else: foreach ($pending as $u): ?>
      <div class="card">
        <img src="avatars/<?php echo htmlspecialchars($u['avatar']); ?>" alt="">
        <div class="info">
          <div class="name"><?php echo htmlspecialchars($u['name']); ?></div>
          <div class="email"><?php echo htmlspecialchars($u['email']); ?></div>
        </div>
        <div class="actions">
          <form method="post" style="display:inline">
            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="approve">Approuver</button>
          </form>
          <form method="post" style="display:inline">
            <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
            <input type="hidden" name="action" value="reject">
            <button type="submit" class="reject" onclick="return confirm('Rejeter cette demande ?')">Rejeter</button>
          </form>
        </div>
      </div>
    <?php endforeach; endif; ?>

    <h2>Comptes actifs</h2>
    <?php foreach ($active as $u): ?>
      <div class="card">
        <img src="avatars/<?php echo htmlspecialchars($u['avatar']); ?>" alt="">
        <div class="info">
          <div class="name"><?php echo htmlspecialchars($u['name']); ?></div>
          <div class="email"><?php echo htmlspecialchars($u['email']); ?></div>
        </div>
        <?php if ($u['is_admin']): ?><span class="badge">admin</span><?php endif; ?>
      </div>
    <?php endforeach; ?>

    <h2>Bibliothèque</h2>
    <p class="empty" style="font-style:normal;">
      Retire des titres les mentions techniques traînantes ("Full HD", "4K",
      "Official Video", "Remaster"...) sur TOUS les comptes, et relance une
      recherche de paroles avec le titre nettoyé pour les morceaux renommés
      qui n'en avaient pas encore.
    </p>
    <button type="button" class="approve" id="clean-titles-btn">Nettoyer les titres de la bibliothèque</button>
    <div id="clean-titles-result" style="margin-top:12px;font-size:0.85rem;"></div>
  </div>

  <script>
    const cleanTitlesBtn = document.getElementById('clean-titles-btn');
    const cleanTitlesResult = document.getElementById('clean-titles-result');
    cleanTitlesBtn.addEventListener('click', async () => {
      if (!confirm("Nettoyer les titres de toute la bibliothèque (tous les comptes) et relancer la recherche de paroles pour les morceaux renommés qui n'en ont pas ? Cette action est immédiate, pas d'aperçu.")) return;
      cleanTitlesBtn.disabled = true;
      cleanTitlesBtn.textContent = 'Nettoyage en cours…';
      cleanTitlesResult.textContent = '';
      try {
        const res = await fetch('admin-clean-titles.php', { method: 'POST' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        const lines = [
          data.renamed_count + ' titre(s) renommé(s).',
          data.lyrics_rechecked + ' vérification(s) de paroles relancée(s), ' + data.lyrics_found_now + ' trouvée(s) grâce au titre nettoyé.'
        ];
        if (data.renamed.length) {
          lines.push('');
          data.renamed.forEach(r => lines.push('#' + r.id + '  "' + r.before + '"  ->  "' + r.after + '"'));
        }
        cleanTitlesResult.textContent = lines.join('\n');
        cleanTitlesResult.style.whiteSpace = 'pre-line';
      } catch (err) {
        cleanTitlesResult.textContent = 'Erreur : ' + err.message;
      } finally {
        cleanTitlesBtn.disabled = false;
        cleanTitlesBtn.textContent = 'Nettoyer les titres de la bibliothèque';
      }
    });
  </script>
</body>
</html>
