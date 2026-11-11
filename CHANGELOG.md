# Changements de l’édition communautaire

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
