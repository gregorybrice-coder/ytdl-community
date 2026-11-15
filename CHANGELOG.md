# Changements de l’édition communautaire

## Nouveautés — 15 novembre 2026

- **Accès à la bibliothèque d’un autre compte** : un compte peut la demander ; le propriétaire accepte ou refuse (profil › Bibliothèques) et est prévenu par notification push, e-mail et pastille. Une fois accordée, elle apparaît dans les playlists du demandeur, au prénom du propriétaire, en lecture seule : écoute et import de titres sans téléchargement du fichier ni copie (simple lien).
- **Import exécuté par le serveur** : une fois lancé, l’import d’une playlist se poursuit même si le téléphone s’éteint, passe en arrière-plan ou perd le réseau. L’appli en affiche l’avancement (disque de progression, liste des titres, bouton « Arrêter l’import »), une notification prévient à la fin, et le processus est relancé automatiquement si le serveur redémarre.
- **Genres musicaux** : chaque titre est rangé automatiquement dans un genre (Deezer puis iTunes, deux services gratuits sans clé, à défaut les mots-clés du titre), en arrière-plan. Seuls l’artiste et le titre sont envoyés à ces services. Un titre non reconnu va dans « Inclassable » et se déplace à la main (menu « … » › Genre, jamais écrasé ensuite). Le genre s’affiche sur le titre, se recherche, et des playlists automatiques « ♫ Genre » ouvrent la liste des playlists. Section « Genres musicaux » dans l’administration.
- **Renouvellement des cookies pour tous** : le bouton « Renouveler la connexion YouTube » apparaît avec l’erreur d’expiration, pour n’importe quel compte (une tentative toutes les 3 minutes, jamais d’envoi de fichier) ; un import de playlist se met en pause au lieu d’échouer titre par titre, puis reprend.
- **Administration — cookies YouTube** : état du fichier, test auprès de YouTube, renouvellement depuis Chrome ou Firefox ou par envoi d’un `cookies.txt`. Seuls les cookies de YouTube et Google sont gardés, et rien n’est installé sans avoir été accepté par YouTube (l’ancien fichier reste en `.bak`).
- **Bibliothèque consultée** : « Prénom › Clips » et « Prénom › Hors musique » à part, en lecture seule.
- **Vidéos privées** : une vidéo privée ou indisponible d’une playlist est ignorée (et indiquée dans le bilan) au lieu d’être comptée comme un échec.
- **Import de playlist YouTube** : un lien `youtube.com/playlist` (ou une vidéo ouverte depuis une playlist) s’importe avec le nom de la playlist YouTube, jusqu’à 1000 titres ; coller le lien dans « Ajouter » ouvre l’import.
- **Partage** : le propriétaire d’un titre apparaît dans la liste de partage ; le lui partager le lui recommande (« partagé par … »).
- **Apparences** : les anciennes apparences en jeux de couleurs (Sombre, Clair, Ambre, Kawaii) sont retirées ; toutes les apparences sont à disque. Étoiles de notation non données : contour net, visible sur tous les fonds. Le clavier du téléphone ne masque plus les fenêtres de saisie, et l’application s’affiche en plein écran sur Android.
- **Aide** : guide « Voir comment faire » pour l’import de playlist ; une couleur par playlist.
- **Sécurité** : fichiers cachés, journaux, documentation, scripts et dépendances jamais servis (insensible à la casse) ; notifications push fonctionnelles (client HTTP ajouté) et jamais bloquantes ; scripts en ligne de commande inaccessibles en HTTP.

## Correctifs de sécurité — 11 novembre 2026

- Page de connexion : les paroles synchronisées, saisies par les utilisateurs, sont affichées comme du texte et plus en HTML (faille XSS visible des visiteurs non connectés).
- Import de playlist : Apple Music et YouTube Music exigent désormais un hôte exact, et le serveur n’appelle qu’une URL reconstruite, jamais le lien brut (faille SSRF vers des adresses internes).
- Requêtes HTTP sortantes limitées au HTTPS, redirections comprises (5 au plus).

## Présentation smartphone — 9 novembre 2026

- Mise en avant de l’interface tactile pour smartphone, de la PWA et de l’installation sur l’écran d’accueil.
- Trois captures mobiles en format portrait, placées avant les captures pour ordinateur dans le README.
- Mention explicite des données fictives et de l’émulation smartphone utilisée pour les captures.

## Présentation et installation neuve — 9 novembre 2026

- Trois captures d’écran (Sillon, Platine et Orbite) avec des données fictives, affichées dans le README.
- Création des colonnes de quota et de playlist de reprise sur une base neuve ; ces colonnes existaient seulement dans la base historique.
- Deux vérifications supplémentaires du schéma d’installation.

## Préparation initiale — 9 novembre 2026

- Licence GPL-3.0-only et historique Git indépendant.
- Suppression des comptes personnels créés à l’initialisation et des clés Web Push de l’instance d’origine.
- Stockage privé et bibliothèque configurables ; dossiers de tâches privés par installation.
- Initialisation du premier administrateur en ligne de commande et génération locale de nouvelles clés.
- Exemples SMTP, routeur local protégé, règles Apache et documentation d’installation.
- Exclusion des données, journaux, dépendances embarquées, médias et illustrations personnelles.
- Tests d’installation isolés et exemple de workflow GitHub Actions à activer.

L’installation personnelle à l’origine de cette copie n’a pas été modifiée.
