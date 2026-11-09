# YTDL — version communautaire

Application musicale auto-hébergée en PHP : téléchargement vidéo ou audio avec yt-dlp, bibliothèque personnelle, playlists, partage entre comptes, paroles, fiches artistes, apparences et installation comme application web.

Cette édition est issue d’une installation personnelle, rendue indépendante de son serveur d’origine. Elle est distribuée sous **GPL-3.0-only**. Elle ne contient aucun compte préconfiguré, bibliothèque musicale, cookie de connexion ou clé de notification de l’instance d’origine.

## Prérequis

- PHP 8.3 ou supérieur, avec PDO SQLite, cURL, mbstring et OpenSSL ; GMP recommandé pour Web Push. Composer vérifie les extensions nécessaires aux dépendances.
- Composer 2.
- `yt-dlp`, `ffmpeg` et `ffprobe` dans le PATH du serveur PHP ; un moteur JavaScript compatible avec votre version de yt-dlp peut être nécessaire pour certaines sources.
- Un service SMTP pour les liens et codes de connexion.
- HTTPS pour une utilisation publique, les passkeys et les notifications push. Le serveur PHP intégré est réservé aux essais locaux.

## Installation locale

Après avoir récupéré le dépôt, placez-vous dans son dossier :

```sh
composer install --no-dev --prefer-dist
export YTDL_DATA_DIR="$(dirname "$PWD")/ytdl-private"
export YTDL_LIBRARY_ROOT="$YTDL_DATA_DIR/library"
php scripts/setup.php --name='Administrateur' --email='vous@example.org' --url='http://localhost:8080/ytdl'
```

L’adresse doit être remplacée par la vôtre. L’initialisation crée un administrateur actif, une base SQLite neuve, une bibliothèque vide et des clés Web Push propres à l’installation. Une seconde initialisation est refusée. Elle ne crée pas de mot de passe : YTDL utilise des liens ou codes envoyés par email, puis éventuellement des passkeys.

Préparez votre SMTP :

```sh
cp config-examples/smtp-config.example.php "$YTDL_DATA_DIR/smtp-config.php"
chmod 600 "$YTDL_DATA_DIR/smtp-config.php"
```

Complétez ce fichier privé avec les paramètres de votre prestataire. Le code existant utilise SMTP avec STARTTLS, généralement sur le port 587. Le champ `from` doit être une adresse autorisée par ce prestataire. Ne commitez jamais ce fichier.

Démarrez ensuite le serveur de développement :

```sh
php -S 127.0.0.1:8080 scripts/dev-router.php
```

Ouvrez **http://localhost:8080/ytdl/**. Le routeur refuse les dépendances, fichiers internes et outils d’administration CLI. Cette édition conserve le chemin historique **`/ytdl/`** utilisé par l’application web, le service worker et ses liens ; un déploiement à la racine n’est pas couvert.

## Configuration et données privées

| Réglage | Utilisation |
| --- | --- |
| `YTDL_DATA_DIR` | Chemin absolu du stockage privé, hors du dossier du site. À défaut : `ytdl-data` à côté du dépôt. |
| `YTDL_LIBRARY_ROOT` | Bibliothèque, par défaut `YTDL_DATA_DIR/library`. Choisir un dossier hors du site. |
| `YTDL_VAPID_PUBLIC_KEY`, `YTDL_VAPID_PRIVATE_KEY`, `YTDL_VAPID_SUBJECT` | Remplacement facultatif des clés créées par l’installation. |
| `YTDL_DATA_DIR/app-config.php` | URL publique utilisée dans les emails (`public_base_url`). |
| `YTDL_DATA_DIR/smtp-config.php` | Configuration SMTP privée. |
| `YTDL_DATA_DIR/vapid-config.php` | Identité Web Push générée localement. |
| `YTDL_DATA_DIR/cookies.txt` | Cookies YouTube facultatifs, sensibles et propres à votre compte. |

Les variables doivent aussi être disponibles dans le processus PHP du serveur web, pas seulement dans le terminal d’installation. La base `users.db`, les caches et les tâches sont stockés dans le dossier privé. Ne le placez jamais sous une autre racine web, même s’il est hors de ce dépôt.

La bibliothèque contient un marqueur `.ytdl-library-volume`. S’il disparaît, l’application refuse d’y écrire : ce garde-fou évite de recréer une bibliothèque au mauvais emplacement après le démontage d’un disque. Pour un disque externe, montez-le avant l’initialisation et avant le démarrage du serveur.

## Déploiement Apache

- Monter le dossier du dépôt sous `/ytdl/` et activer PHP ainsi que les règles `.htaccess` (`AllowOverride All`, modules d’autorisation et `mod_alias`).
- Configurer les variables du stockage privé pour le processus PHP, les permissions du compte du serveur et l’URL HTTPS dans `app-config.php`.
- Utiliser TLS, protéger les sauvegardes et vérifier les règles d’accès avant d’exposer le serveur.
- Les dossiers `.git`, `vendor`, `scripts`, `tests`, `config-examples` et les fichiers PHP internes sont interdits par les règles fournies. Ne pas publier le dépôt avec un serveur ignorant ces règles sans protection équivalente.
- Vérifier l’envoi SMTP avant de compter sur la connexion par email. Les passkeys sont liées au domaine : celles d’une autre instance ne sont pas transférables.

## Maintenance

```sh
sh maintenance.sh on 'Maintenance en cours'
sh maintenance.sh off
```

Ces commandes utilisent `YTDL_DATA_DIR`. L’administrateur garde accès à l’application. Sauvegardez le dossier privé et la bibliothèque ensemble ; une base SQLite active doit être sauvegardée avec une méthode adaptée à SQLite/WAL.

Le script facultatif `install-cookies.sh` exporte les cookies du navigateur choisi vers le stockage privé. Exécutez-le vous-même après lecture du script. Aucun cookie n’est fourni ni requis pour démarrer l’application.

Les outils `migrate-*.php`, `admin-clean-titles.php` et `archive-artists.php` viennent de l’application historique. Ils ne sont pas requis pour une installation neuve ; vérifiez leur comportement sur une sauvegarde avant toute maintenance de données.

## Vérifications

```sh
composer validate --strict
php tests/install.php
```

Le test d’installation crée exclusivement des données temporaires : base vide, premier administrateur, clés privées, refus d’une seconde initialisation et configuration SMTP sans envoi. Un [exemple de workflow GitHub Actions](config-examples/github-actions-checks.yml) prévoit PHP 8.3, 8.4 et 8.5. Pour l’activer, copiez-le dans `.github/workflows/checks.yml` avec une autorisation GitHub permettant de modifier les workflows. Il n’est pas encore actif sur ce dépôt. Ces tests ne couvrent pas les échanges réels avec YouTube, SMTP, les passkeys ou les services de notifications.

## Apparences et médias

Les styles sont inclus. Les illustrations personnelles des apparences et leurs aperçus ne sont pas distribués. Les fichiers `ui/skins/*/LISEZMOI.txt` expliquent comment fournir vos propres illustrations ; les styles utilisent leurs fonds de repli lorsqu’aucune image n’est présente. Aucun média téléchargé ni catalogue de l’installation d’origine n’est inclus.

## Contributions et licence

Voir [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md) et le texte complet de la [GPLv3](LICENSE). Les dépendances conservent leurs licences propres.

Utilisez l’application pour les contenus dont le téléchargement et l’usage vous sont autorisés. Les fichiers musicaux, paroles, images et données de services externes ne deviennent pas couverts par la GPL du fait de leur utilisation dans YTDL.
