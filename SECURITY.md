# Sécurité

Cette première édition communautaire n’a pas fait l’objet d’un audit de sécurité complet. Les tests automatisés portent sur l’installation et l’isolation des données, pas sur l’ensemble des endpoints historiques.

Ne publiez pas une faille avec des identifiants ou des données personnelles dans une issue publique. Si le dépôt propose le signalement privé de vulnérabilités GitHub, utilisez cette fonction ; sinon contactez le mainteneur par un canal privé connu.

Ne servez jamais le dossier des données, la bibliothèque, les cookies, les sauvegardes ou les fichiers de configuration privés. Utilisez HTTPS en production et maintenez PHP, Composer, yt-dlp et FFmpeg à jour.

Une clé VAPID est générée pour chaque installation ; la clé privée reste hors du dépôt et de la racine web. SMTP et cookies sont fournis par l’exploitant et ne sont pas partagés avec les contributeurs.
