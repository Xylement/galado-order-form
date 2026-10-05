#!/usr/bin/env bash
# Runs every tests/integration/test-*.php inside the throwaway test WordPress (WooCommerce
# included) that galado-gift-cards/tests/integration/setup.sh builds, each on a freshly reset
# database, with this plugin mounted read-only. Never points at the live store.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
. "$HERE/../../../galado-gift-cards/tests/integration/env.sh"
PLUGIN="$(cd "$HERE/../.." && pwd)"
docker exec "$GCT_DB" sh -c 'test -s /tmp/gct-baseline.sql' 2>/dev/null \
  || { echo "No test WordPress yet: run galado-gift-cards/tests/integration/setup.sh first."; exit 2; }
wpc() {
  docker run --rm -i --network "$GCT_NET" -v "$GCT_VOL":/var/www/html \
    -v "$PLUGIN":/var/www/html/wp-content/plugins/galado-studio-cart:ro \
    -e HOME=/tmp --user 33:33 --entrypoint php wordpress:cli -d memory_limit=1G /usr/local/bin/wp "$@"
}
failed=0
for f in $(cd "$HERE" && ls test-*.php | sort); do
  docker exec "$GCT_DB" sh -c 'mariadb -uroot -proot -e "DROP DATABASE wp; CREATE DATABASE wp" && mariadb -uroot -proot wp < /tmp/gct-baseline.sql'
  wpc plugin activate galado-studio-cart >/dev/null
  out=$(wpc eval-file "/var/www/html/wp-content/plugins/galado-studio-cart/tests/integration/$f" 2>&1)
  code=$?
  printf '%-28s %-5s %s\n' "$f" "$([ $code -eq 0 ] && echo ok || echo FAIL)" "$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed' | tail -1)"
  if [ $code -ne 0 ]; then failed=1; printf '%s\n' "$out" | grep -vE '^ok ' | sed 's/^/    /'; fi
done
exit $failed
