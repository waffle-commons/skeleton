#!/usr/bin/env bash
# Crée un projet Waffle depuis waffle-commons/skeleton avec Docker seulement : ni PHP ni
# Composer sur la machine hôte. Composer tourne dans son image officielle, épinglée par
# empreinte comme dans docker/Dockerfile. Les extensions redis et yaml manquent à cette
# image : elles ne sont pas vérifiées ici, l'image de l'application les installe.
#
# Récupérez le script à la version voulue, lisez-le, puis lancez-le :
#   curl -fsSLO https://raw.githubusercontent.com/waffle-commons/skeleton/0.1.0-beta7/create-project.sh
#   bash create-project.sh mon-app
#
# Compatible avec le bash 3.2 de macOS. Absent des projets créés (export-ignore).
set -euo pipefail

# Estampillés à chaque publication, avec le README.
WAFFLE_VERSION='0.1.0-beta7'
COMPOSER_IMAGE='composer:2.10.3@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee'

usage() {
  cat <<EOF
Usage : bash create-project.sh [--version <version>] <dossier>

Crée un projet Waffle dans <dossier> avec Docker seulement (ni PHP ni Composer sur l'hôte).

  --version <version>  version du squelette (défaut : ${WAFFLE_VERSION})
  -h, --help           affiche cette aide
EOF
}

fail() {
  printf 'Erreur : %s\n' "$1" >&2
  exit 1
}

version="$WAFFLE_VERSION"
target=''
while [ $# -gt 0 ]; do
  case "$1" in
    --version)
      [ $# -ge 2 ] || fail '--version attend une valeur (voir --help).'
      version="$2"
      shift 2
      ;;
    --version=*)
      version="${1#--version=}"
      shift
      ;;
    -h | --help)
      usage
      exit 0
      ;;
    -*) fail "option inconnue : $1 (voir --help)." ;;
    *)
      [ -z "$target" ] || fail 'un seul dossier attendu (voir --help).'
      target="$1"
      shift
      ;;
  esac
done

if [ -z "$target" ]; then
  usage >&2
  exit 1
fi
[ -n "$version" ] || fail '--version est vide.'

command -v docker >/dev/null 2>&1 || fail 'Docker est introuvable : installez-le (https://docs.docker.com/get-docker/).'
docker info >/dev/null 2>&1 || fail 'le démon Docker ne répond pas : démarrez Docker, puis relancez.'

# Composer exige un dossier absent ou vide. Le parent existe : il est monté dans le conteneur.
if [ -e "$target" ] && [ -n "$(ls -A -- "$target" 2>/dev/null)" ]; then
  fail "« $target » existe déjà et n'est pas vide."
fi
parent=$(dirname -- "$target")
name=$(basename -- "$target")
[ -d "$parent" ] || fail "le dossier parent « $parent » n'existe pas."
parent_abs=$(cd -- "$parent" && pwd -P)

# Le conteneur tourne sous l'utilisateur courant : sous Linux, les fichiers créés lui
# appartiennent, pas à root. COMPOSER_HOME doit alors être inscriptible.
tty_flag=''
if [ -t 1 ]; then
  tty_flag='-t'
fi

printf 'Création de « %s » depuis waffle-commons/skeleton:%s (Composer dans Docker)…\n' "$target" "$version"
if ! docker run --rm ${tty_flag:+"$tty_flag"} \
  --user "$(id -u):$(id -g)" \
  -e COMPOSER_HOME=/tmp/composer \
  -v "$parent_abs:/app" -w /app \
  "$COMPOSER_IMAGE" \
  create-project "waffle-commons/skeleton:$version" "$name" \
  --no-interaction --prefer-dist \
  --ignore-platform-req=ext-redis --ignore-platform-req=ext-yaml; then
  fail "Composer n'a pas pu créer le projet (voir son message ci-dessus)."
fi

printf '\n✅ Projet créé dans « %s ».\n\nÉtapes suivantes :\n' "$target"
printf '  cd %q\n' "$target"
cat <<'EOF'
  docker compose up -d --build     # pile de développement : FrankenPHP, Redis, PostgreSQL
  curl -k https://localhost/healthz

Les secrets de développement sont générés dans .env. En production, injectez
WAFFLE_CSRF_SECRET et WAFFLE_AUTH_SECRET (32 octets ou plus) : voir README.md.
EOF
