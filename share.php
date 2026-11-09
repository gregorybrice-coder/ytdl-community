<?php
require_once __DIR__ . '/auth.php';
$user = ytdl_current_user();

if (!$user) {
    // Pas connecté : direction la page de connexion, on perd le lien partagé
    // mais on évite de traîner du GET non authentifié plus loin.
    header('Location: index.php');
    exit;
}

// Les apps Android mettent généralement le lien dans "text" (parfois "url").
$shared = trim((string)($_GET['url'] ?? '')) ?: trim((string)($_GET['text'] ?? ''));

if (preg_match('#https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?v=|shorts/)|youtu\.be/)[\w-]+(?:[^\s]*)?#i', $shared, $m)) {
    // Tableau, pas une simple valeur : chaque partage s'ajoute à la liste
    // d'attente au lieu d'écraser un éventuel lien partagé juste avant et pas
    // encore consommé par capture.php (ex : deux partages successifs sans
    // avoir eu le temps de télécharger le premier).
    if (!isset($_SESSION['shared_urls']) || !is_array($_SESSION['shared_urls'])) {
        $_SESSION['shared_urls'] = [];
    }
    $_SESSION['shared_urls'][] = $m[0];
}

header('Location: capture.php');
