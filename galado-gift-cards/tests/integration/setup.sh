#!/usr/bin/env bash
# One-time: build the test WordPress (WordPress 6.9.9 + WooCommerce 10.5.3, MYR, Asia/Kuala_Lumpur,
# orders in posts like the live store) and save a clean baseline. Needs Docker. Safe to re-run.
set -euo pipefail
. "$(dirname "$0")/env.sh"
docker network inspect "$GCT_NET" >/dev/null 2>&1 || docker network create "$GCT_NET" >/dev/null
docker ps -a --format '{{.Names}}' | grep -qx "$GCT_DB" || docker run -d --name "$GCT_DB" --network "$GCT_NET" \
  -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11 >/dev/null
docker volume inspect "$GCT_VOL" >/dev/null 2>&1 || docker volume create "$GCT_VOL" >/dev/null
docker run --rm -v "$GCT_VOL":/var/www/html --user root --entrypoint sh wordpress:cli -c \
  'mkdir -p /var/www/html/wp-content/plugins && chown -R 33:33 /var/www/html'
for i in $(seq 1 60); do docker exec "$GCT_DB" mariadb -uwp -pwp -e 'select 1' wp >/dev/null 2>&1 && break; sleep 2; done
gct_wp core download --version="$GCT_WP" --locale=en_US --force
gct_wp config create --dbname=wp --dbuser=wp --dbpass=wp --dbhost="$GCT_DB" --skip-check --force
gct_wp core install --url=http://gct.test --title=GCT --admin_user=admin --admin_password="$(head -c 18 /dev/urandom | base64)" \
  --admin_email=admin@example.test --skip-email
gct_wp option update timezone_string Asia/Kuala_Lumpur
docker run --rm -v "$GCT_VOL":/var/www/html --user 33:33 --entrypoint sh wordpress:cli -c \
  "cd /tmp && curl -sSfL -o wc.zip https://downloads.wordpress.org/plugin/woocommerce.${GCT_WC}.zip && cd /var/www/html/wp-content/plugins && unzip -q -o /tmp/wc.zip"
gct_wp plugin activate woocommerce
gct_wp option update woocommerce_currency MYR
gct_wp option update woocommerce_default_country MY
gct_wp option update woocommerce_enable_coupons yes
gct_wp option update woocommerce_calc_taxes no
gct_wp option update woocommerce_custom_orders_table_enabled no
gct_wp option update woocommerce_coming_soon no
# Optional: WooCommerce Points and Rewards (a paid Woo extension, not downloadable here). Point
# GCT_PR_DIR at a copy of the plugin folder (live runs 1.6.13); run.sh then activates it for every
# file and test-spending.php checks Shopping Credits against the real plugin.
if [ -n "${GCT_PR_DIR:-}" ] && [ -d "$GCT_PR_DIR" ]; then
  docker run --rm -v "$GCT_VOL":/var/www/html -v "$GCT_PR_DIR":/src:ro --user root --entrypoint sh wordpress:cli -c \
    'rm -rf /var/www/html/wp-content/plugins/woocommerce-points-and-rewards && cp -a /src /var/www/html/wp-content/plugins/woocommerce-points-and-rewards && chown -R 33:33 /var/www/html/wp-content/plugins/woocommerce-points-and-rewards'
fi
docker exec "$GCT_DB" sh -c 'mariadb-dump -uroot -proot --single-transaction wp > /tmp/gct-baseline.sql'
echo "Test WordPress ready. Run: tests/integration/run.sh"
