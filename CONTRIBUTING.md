# Contribuer

Ouvrez une issue pour décrire le problème, les étapes de reproduction, votre version de PHP et de yt-dlp, puis proposez une modification ciblée dans une pull request.

Ne joignez jamais de cookies, emails d’utilisateurs, base SQLite, clés VAPID, paramètres SMTP, liens de connexion ou journaux non relus.

Avant une proposition :

```sh
composer install
composer validate --strict
php tests/install.php
```

Vérifiez aussi la syntaxe des fichiers PHP modifiés. Pour une modification fonctionnelle, ajoutez un test ciblé et précisez les cas manuels vérifiés. Testez exclusivement avec un dossier privé et une bibliothèque de développement.

Les contributions sont proposées sous GPL-3.0-only. Les illustrations ajoutées doivent pouvoir être redistribuées, avec leur origine et leur licence documentées.
