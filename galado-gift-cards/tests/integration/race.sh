#!/usr/bin/env bash
# Two real PHP processes issue the same paid order at the same moment.
#  - through the plugin: exactly 2 codes (one per card), the second process waits for the lock;
#  - negative control, the same check-then-create without the lock: duplicates. If the control
#    ever stops duplicating, the race window is not being hit and the first result proves nothing.
set -uo pipefail
. "$(dirname "$0")/env.sh"
F=/var/www/html/wp-content/plugins/galado-gift-cards/tests/integration/race.php
q() { gct_wp eval-file "$F" "$@" 2>/dev/null | grep -v wp_is_block_theme; }
ok=1
id=$(q setup | tr -dc 0-9)
q locked "$id" & q locked "$id" & wait
n=$(q count "$id" | tr -dc 0-9)
if [ "$n" = "2" ]; then echo "race (locked)                      ok     2 codes for 2 cards"; else echo "race (locked)                      FAIL   $n codes for 2 cards"; ok=0; fi
id=$(q setup | tr -dc 0-9)
q naive "$id" & q naive "$id" & wait
n=$(q count "$id" | tr -dc 0-9)
if [ "${n:-0}" -gt 2 ]; then echo "race (no lock, negative control)   ok     $n codes for 2 cards: the race is real"; else echo "race (no lock, negative control)   FAIL   only $n codes: the race window was not hit"; ok=0; fi
[ $ok -eq 1 ]
