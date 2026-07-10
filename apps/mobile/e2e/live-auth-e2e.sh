#!/usr/bin/env bash
# Live E2E of the T-003 auth endpoints the mobile client calls, against Sail:8080.
# Exercises the exact request shape (incl. device_name) and asserts the response
# envelope the axios client in apps/mobile/src/api parses.
set -uo pipefail
# Override for a LAN host / staging: API_URL=http://192.168.1.10:8080 ./live-auth-e2e.sh
BASE="${API_URL:-http://localhost:8080}/api/v1"
STAMP=$(date +%s)
EMAIL="e2e_${STAMP}@example.com"
USER="e2e_${STAMP}"
PASS="Sup3r-secret!${STAMP}"
DEVICE="e2e-sim"
PASS_N=0
FAIL_N=0
ok()  { echo "  ✓ $1"; PASS_N=$((PASS_N + 1)); }
bad() { echo "  ✗ $1"; FAIL_N=$((FAIL_N + 1)); }

# Assertion helpers (explicit if/else — no `A && B || C` foot-guns).
# expect_code ACTUAL LABEL DETAIL ALLOWED...
expect_code() {
  local actual="$1" label="$2" detail="$3"; shift 3
  local c
  for c in "$@"; do
    if [ "$actual" = "$c" ]; then ok "$label (HTTP $actual)"; return; fi
  done
  bad "$label — HTTP $actual ${detail}"
}
expect_eq() { # ACTUAL EXPECTED LABEL
  if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 — expected '$2' got '$1'"; fi
}
expect_nonempty() { # VALUE LABEL DETAIL
  if [ -n "$1" ] && [ "$1" != "None" ]; then ok "$2"; else bad "$2 — empty ${3:-}"; fi
}

# Safe dotted-path lookup into a JSON body (no eval). Usage: jqget "$BODY" data.token
# The JSON body arrives on stdin (parsed as data, not code); only the hardcoded
# dotted path is interpolated into the program text.
jqget() {
  echo "$1" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    sys.exit(0)
for k in '$2'.split('.'):
    if isinstance(d, dict) and k in d:
        d = d[k]
    else:
        sys.exit(0)
print('' if d is None else d)
" 2>/dev/null
}

echo "== 1. register =="
REG=$(curl -s -w '\n%{http_code}' -X POST "$BASE/auth/register" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"name\":\"E2E Maya\",\"username\":\"$USER\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"device_name\":\"$DEVICE\"}")
REG_CODE=$(echo "$REG" | tail -1); REG_BODY=$(echo "$REG" | sed '$d')
expect_code "$REG_CODE" "register" "$REG_BODY" 200 201
TOKEN=$(jqget "$REG_BODY" data.token)
expect_nonempty "$TOKEN" "register returned data.token" "$REG_BODY"
RUSER=$(jqget "$REG_BODY" data.user.username)
expect_eq "$RUSER" "$USER" "register returned data.user.username=$RUSER"

echo "== 2. GET /me with bearer =="
ME=$(curl -s -w '\n%{http_code}' "$BASE/me" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN")
ME_CODE=$(echo "$ME" | tail -1); ME_BODY=$(echo "$ME" | sed '$d')
expect_code "$ME_CODE" "me" "$ME_BODY" 200
# /me nests the user under data.user (MeController), same shape the mobile
# fetchMe() reads via data.data.user.
MEMAIL=$(jqget "$ME_BODY" data.user.email)
expect_eq "$MEMAIL" "$EMAIL" "me returned data.user.email=$MEMAIL"

echo "== 3. GET /me without bearer -> 401 =="
UN_CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/me" -H 'Accept: application/json')
expect_code "$UN_CODE" "unauth me" "" 401

echo "== 4. login (new device token) =="
LOGIN=$(curl -s -w '\n%{http_code}' -X POST "$BASE/auth/login" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"device_name\":\"$DEVICE-2\"}")
L_CODE=$(echo "$LOGIN" | tail -1); L_BODY=$(echo "$LOGIN" | sed '$d')
expect_code "$L_CODE" "login" "$L_BODY" 200
LTOKEN=$(jqget "$L_BODY" data.token)
expect_nonempty "$LTOKEN" "login returned data.token" "$L_BODY"

echo "== 5. login wrong password -> 422 with field errors =="
WRONG=$(curl -s -w '\n%{http_code}' -X POST "$BASE/auth/login" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"nope\",\"device_name\":\"$DEVICE\"}")
W_CODE=$(echo "$WRONG" | tail -1); W_BODY=$(echo "$WRONG" | sed '$d')
expect_code "$W_CODE" "wrong-password" "$W_BODY" 422
WDETAILS=$(jqget "$W_BODY" error.details)
expect_nonempty "$WDETAILS" "422 carries error.details (maps to form errors): $WDETAILS" "$W_BODY"

echo "== 6. logout revokes the login token =="
LO_CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/auth/logout" -H 'Accept: application/json' -H "Authorization: Bearer $LTOKEN")
expect_code "$LO_CODE" "logout" "" 200 204
AFTER=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/me" -H 'Accept: application/json' -H "Authorization: Bearer $LTOKEN")
expect_code "$AFTER" "revoked token rejected (matches client 401 clear+redirect)" "" 401

echo "== 7. register duplicate email -> 422 =="
DUP_CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/auth/register" \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"name\":\"Dup\",\"username\":\"${USER}_x\",\"email\":\"$EMAIL\",\"password\":\"$PASS\",\"password_confirmation\":\"$PASS\",\"device_name\":\"$DEVICE\"}")
expect_code "$DUP_CODE" "duplicate email" "" 422

echo ""
echo "RESULT: $PASS_N passed, $FAIL_N failed"
[ "$FAIL_N" = "0" ]
