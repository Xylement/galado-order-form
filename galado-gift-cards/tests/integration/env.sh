# Throwaway local test WordPress for galado-gift-cards. Never points at the live store.
# Override any of these before sourcing.
: "${GCT_NET:=gcint}"
: "${GCT_DB:=gcint-db}"
: "${GCT_VOL:=gcint-wp}"
: "${GCT_WP:=6.9.9}"
: "${GCT_WC:=10.5.3}"
GCT_PLUGIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# wp-cli inside the official image, with our plugin mounted read-only.
gct_wp() {
  docker run --rm -i --network "$GCT_NET" -v "$GCT_VOL":/var/www/html \
    -v "$GCT_PLUGIN":/var/www/html/wp-content/plugins/galado-gift-cards:ro \
    -e HOME=/tmp --user 33:33 --entrypoint php wordpress:cli -d memory_limit=1G /usr/local/bin/wp "$@"
}
