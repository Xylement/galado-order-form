#!/usr/bin/env bash
# Runs every tests/integration/test-*.php inside the test WordPress, each on a freshly reset
# database, with orders in posts (as live) and then again with HPOS on for the order tests.
#   tests/integration/run.sh            all files
#   tests/integration/run.sh issuing    only files whose name contains "issuing"
set -uo pipefail
. "$(dirname "$0")/env.sh"
reset_db() {
  # Drop and recreate, not just re-import: tables made after the baseline (HPOS order tables,
  # Points and Rewards) would otherwise keep rows from the previous file under reused order ids.
  docker exec "$GCT_DB" sh -c 'mariadb -uroot -proot -e "DROP DATABASE wp; CREATE DATABASE wp" && mariadb -uroot -proot wp < /tmp/gct-baseline.sql'
  gct_wp plugin activate galado-gift-cards >/dev/null
  # Live has Points and Rewards active; when setup.sh installed it (GCT_PR_DIR), so do the tests.
  if gct_wp plugin is-installed woocommerce-points-and-rewards 2>/dev/null; then
    gct_wp plugin activate woocommerce-points-and-rewards >/dev/null 2>&1
    # It builds its tables and default settings only on an admin page load: run that step, then
    # the live rates (1 point per RM2 spent, 10 points = RM1).
    gct_wp eval '$m = new ReflectionMethod("WC_Points_Rewards", "install"); $m->setAccessible(true); $m->invoke($GLOBALS["wc_points_rewards"]);
      update_option("wc_points_rewards_earn_points_ratio", "1:2"); update_option("wc_points_rewards_redeem_points_ratio", "10:1");' >/dev/null 2>&1
  fi
}
enable_hpos() {
  # Points and Rewards 1.6.13 does not declare HPOS support, and WooCommerce will not switch HPOS
  # on while an incompatible plugin is active (the same holds on live).
  gct_wp plugin deactivate woocommerce-points-and-rewards >/dev/null 2>&1
  gct_wp wc hpos enable >/dev/null 2>&1
  local on; on=$(gct_wp eval 'echo \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "on" : "off";' 2>/dev/null)
  [ "$on" = "on" ] || { echo "could not switch HPOS on"; return 1; }
}
failed=()
run_file() { # $1 file, $2 label
  local out; out=$(gct_wp eval-file "/var/www/html/wp-content/plugins/galado-gift-cards/tests/integration/$1" 2>&1)
  local code=$?
  local summary; summary=$(printf '%s\n' "$out" | grep -E '^[0-9]+ passed' | tail -1)
  printf '%-34s %-6s %s\n' "$1$2" "$([ $code -eq 0 ] && echo ok || echo FAIL)" "$summary"
  if [ $code -ne 0 ]; then failed+=("$1$2"); printf '%s\n' "$out" | grep -vE '^ok ' | sed 's/^/    /'; fi
}
filter="${1:-}"
for f in $(cd "$(dirname "$0")" && ls test-*.php | sort); do
  [[ -n "$filter" && "$f" != *"$filter"* ]] && continue
  reset_db; run_file "$f" ""
done
# Orders under HPOS (custom order tables): the order-heavy files again.
for f in test-issuing.php test-revoke.php test-delivery.php; do
  [[ -n "$filter" && "$f" != *"$filter"* ]] && continue
  reset_db
  if enable_hpos; then run_file "$f" " [HPOS]"; else failed+=("$f [HPOS could not be enabled]"); fi
done
# Two real PHP processes racing to issue the same order (see race.php).
if [[ -z "$filter" || "race" == *"$filter"* ]]; then
  reset_db; "$(dirname "$0")/race.sh" || failed+=("race")
fi
echo
if [ ${#failed[@]} -eq 0 ]; then echo "All integration tests passed (WordPress $GCT_WP, WooCommerce $GCT_WC)."; else echo "FAILED: ${failed[*]}"; exit 1; fi
