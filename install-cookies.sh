#!/bin/sh
# Exporte les cookies YouTube vers le fichier lu par ytdl.
#
#   sh install-cookies.sh            # depuis Chrome (defaut)
#   sh install-cookies.sh firefox    # depuis Firefox
#
# IMPORTANT (Chrome) : a lancer depuis Terminal.app, pas depuis une session
# Claude Code ni un script distant. Chrome chiffre ses cookies avec une cle du
# trousseau macOS, qui exige une autorisation affichee a l'ecran. Sans elle :
# "find-generic-password failed". Firefox n'a pas cette contrainte.

BROWSER="${1:-chrome}"
DATA_DIR=${YTDL_DATA_DIR:?Définir YTDL_DATA_DIR avant cette commande}
DEST="$DATA_DIR/cookies.txt"
mkdir -p "$DATA_DIR"
chmod 700 "$DATA_DIR"
umask 077
TEST_URL='https://www.youtube.com/watch?v=kJQP7kiw5Fk'

echo "Export des cookies $BROWSER vers $DEST"
[ "$BROWSER" = "chrome" ] && echo "(autorise la demande de trousseau si macOS l'affiche)"
echo

yt-dlp --cookies-from-browser "$BROWSER" \
  --cookies "$DEST" \
  --skip-download --print '%(title)s' \
  -- "$TEST_URL"
STATUS=$?

# Un export peut "reussir" en n'extrayant aucun cookie de session : yt-dlp ecrit
# alors un fichier valide mais anonyme, qui ne debloque rien tout en faisant
# croire au site que les cookies sont installes. On verifie donc la presence
# d'un cookie d'authentification, et on efface le fichier sinon.
if [ $STATUS -eq 0 ] && grep -qE '(^|[[:space:]])(SAPISID|__Secure-1PSID|LOGIN_INFO)[[:space:]]' "$DEST" 2>/dev/null; then
    chmod 600 "$DEST"
    echo
    echo "OK : cookies installes dans $DEST"
    echo "Le site les utilise immediatement, rien d'autre a faire."
    exit 0
fi

rm -f "$DEST"
echo
echo "ECHEC : aucun cookie de session valide n'a ete extrait de $BROWSER."
echo "Le fichier a ete supprime (un fichier anonyme ne debloquerait rien)."
echo
if [ "$BROWSER" = "chrome" ]; then
    echo "Pistes :"
    echo "  - relancer cette commande depuis Terminal.app et autoriser le trousseau"
    echo "  - verifier que le compte YouTube est bien connecte dans Chrome"
    echo "  - ou se connecter a YouTube dans Firefox, puis : sh $0 firefox"
else
    echo "Verifier que le compte YouTube est bien connecte dans Firefox."
fi
exit 1
