# Changements de l’édition communautaire

## Nouveautés — 15 novembre 2026

- **Accès à la bibliothèque d’un autre compte** : un compte peut la demander ; le propriétaire accepte ou refuse (profil › Bibliothèques) et est prévenu par notification push, e-mail et pastille. Une fois accordée, elle apparaît dans les playlists du demandeur, au prénom du propriétaire, en lecture seule : écoute et import de titres sans téléchargement du fichier ni copie (simple lien).
- **Import exécuté par le serveur** : une fois lancé, l’import d’une playlist se poursuit même si le téléphone s’éteint, passe en arrière-plan ou perd le réseau. L’appli en affiche l’avancement (disque de progression, liste des titres, bouton « Arrêter l’import »), une notification prévient à la fin, et le processus est relancé automatiquement si le serveur redémarre.
- **Inviter quelqu’un à écouter avec soi** : dans l’écran de l’écoute partagée, un bouton par compte envoie une notification push (une invitation par minute et par personne, valable 10 minutes). L’invité la voit aussi à l’ouverture de l’appli et rejoint le titre ou le clip d’un toucher.
- **Clips : le son continue en arrière-plan** : quand le téléphone coupe la vidéo (écran verrouillé, autre appli), le son du clip passe sur le lecteur audio, à la même seconde, puis le clip reprend au retour. Fonctionne aussi en écoute partagée.
- **Clips : barre de volume sous Safari** : le curseur et le bouton muet du lecteur de clip agissent à nouveau (Safari ignorait le volume de l’élément passé par la normalisation).
- **Écoute partagée : les clips aussi** : un clip regardé est partagé comme un morceau. Les autres le voient dans la liste (« Regarder le clip avec… »), le lancent à la même seconde et le suivent : changement de clip, recalage, pause et reprise, passage d’un clip à un morceau et inversement. Fermer le clip, le mettre en pause ou s’y déplacer quitte l’écoute partagée.
- **Écoute partagée : suivi automatique et notification de l’hôte** : après avoir rejoint quelqu’un, l’écoute le suit (changement de titre repris en moins de 3 secondes, recalage s’il avance ou recule, pause puis reprise, enchaînement à la fin d’un titre) ; un geste de l’utilisateur (autre titre, pause, déplacement) quitte le suivi. L’hôte est prévenu discrètement (« X écoute avec toi », point sur le casque, ligne dans l’écran de l’écoute partagée). Correction : les feuilles n’affichent plus « null » à la place d’un élément absent.
- **Partager ce que j’écoute** : chacun peut activer le partage de son écoute (désactivé par défaut). Un casque dans l’en-tête, avec une pastille, montre qui écoute quoi ; toucher le titre lance le même morceau à la même seconde (position recalculée quand le fichier est prêt, bouton « Resynchroniser »). Seul le titre partagé devient lisible par les autres comptes, jamais téléchargeable, et plus rien à la coupure du partage. La position est enregistrée toutes les 4 s et à chaque déplacement pendant le partage.
- **Bouton « Genres »** : les playlists automatiques par genre ont leur propre catégorie (bouton étiquette en tête du menu, au-dessus de « Morceaux »), séparée des playlists. Un genre venu des bases est ramené au nom déjà existant (« Hip-hop » = « Hip hop ») et les doublons d’écriture sont fusionnés.
- **Genre proposé à l’ajout** : à la fin d’un ajout, le genre de l’artiste est cherché dans MusicBrainz (puis Wikidata) et proposé ; l’utilisateur le garde, le change pour un genre existant ou en crée un. Un genre qui existe déjà (bibliothèque, liste officielle de MusicBrainz, environ 2 200 genres), même écrit autrement (« hip-hop », « Hip Hop »), n’est jamais recréé : recherche pendant la saisie et contrôle côté serveur. Les requêtes vers MusicBrainz sont limitées à une par seconde, tous processus confondus.
- **Genres musicaux** : le genre de chaque titre vient d’une base musicale ouverte et mondiale, jamais d’une règle du programme : celui de l’artiste dans MusicBrainz (genres votés par la communauté), à défaut dans Wikidata, avec le nom donné par la base. Seul le nom de l’artiste est envoyé à ces services (une requête par seconde, résultats gardés en cache par artiste). Un artiste inconnu des bases va dans « Inclassable » ; chacun déplace ses titres à la main (menu « … » › Genre : genre existant ou saisi, jamais écrasé ensuite). Le genre s’affiche sur le titre, se recherche, et des playlists automatiques « ♫ Genre » (à partir de 3 titres) ouvrent la liste des playlists. Section « Genres musicaux » dans l’administration.
- **Renouvellement des cookies pour tous** : le bouton « Renouveler la connexion YouTube » apparaît avec l’erreur d’expiration, pour n’importe quel compte (une tentative toutes les 3 minutes, jamais d’envoi de fichier) ; un import de playlist se met en pause au lieu d’échouer titre par titre, puis reprend.
- **Renouvellement des cookies en PHP** : `install-cookies.php` remplace le script shell ; même logique que l’administration (filtrage, vérification par YouTube, sauvegarde de l’ancien fichier).
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
