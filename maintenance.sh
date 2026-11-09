#!/bin/sh
# Active ou desactive le mode maintenance du site.
#
#   sh maintenance.sh on              # ferme le site (message par defaut)
#   sh maintenance.sh on "message"    # ferme avec un message personnalise
#   sh maintenance.sh off             # rouvre le site
#   sh maintenance.sh                 # affiche l'etat courant
#
# L'admin garde l'acces au site pendant la maintenance.

DATA_DIR=${YTDL_DATA_DIR:?Définir YTDL_DATA_DIR avant cette commande}
FLAG="$DATA_DIR/maintenance.txt"

case "$1" in
    on)
        if [ -n "$2" ]; then
            printf '%s\n' "$2" > "$FLAG"
        else
            : > "$FLAG"
        fi
        echo "Site FERME (maintenance active)."
        [ -s "$FLAG" ] && echo "Message : $(cat "$FLAG")"
        echo "Tu gardes l'acces en tant qu'admin."
        ;;
    off)
        rm -f "$FLAG"
        echo "Site ROUVERT."
        ;;
    '')
        if [ -f "$FLAG" ]; then
            echo "Etat : FERME (maintenance active)"
            [ -s "$FLAG" ] && echo "Message : $(cat "$FLAG")"
        else
            echo "Etat : ouvert"
        fi
        ;;
    *)
        echo "Usage : sh $0 [on [message] | off]" >&2
        exit 1
        ;;
esac
