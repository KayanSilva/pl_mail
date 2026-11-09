#!/usr/bin/env bash
# Health of the local plMail deployment (Docker Compose project "pl_mail").
# Read-only, no network beyond localhost, < 60 s. Output follows the ia-harness doctor format
# ("  OK     ", "  AVISO  ", "  FALHA  "); exits 1 if any check fails.
set -uo pipefail
FALHAS=0
ok()    { printf '  OK     %s\n' "$1"; }
falha() { printf '  FALHA  %s\n' "$1"; [[ -n "${2:-}" ]] && printf '         → %s\n' "$2"; FALHAS=$((FALHAS + 1)); }

if ! command -v docker >/dev/null 2>&1; then
  falha "pl_mail: docker not found" "start Docker"
  exit 1
fi

for c in pl_mail-php-1 pl_mail-database-1; do
  st="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}no healthcheck{{end}}' "$c" 2>/dev/null || echo missing)"
  if [[ "$st" == healthy ]]; then ok "pl_mail: $c healthy"
  else falha "pl_mail: $c is $st" "docker compose up -d (in the repo root); docker logs $c --tail 50"; fi
done

# -4: compose publishes on 127.0.0.1; plain localhost may resolve to ::1 first.
if curl -4kfsS -m 5 -o /dev/null https://localhost/healthz 2>/dev/null; then ok "pl_mail: https://localhost/healthz answers 200"
else falha "pl_mail: https://localhost/healthz does not answer 200" "docker logs pl_mail-php-1 --tail 50"; fi

# Every published port of the php container must be bound to 127.0.0.1 (compose.override.yaml sets host_ip).
ports="$(docker port pl_mail-php-1 2>/dev/null)"
if [[ -z "$ports" ]]; then falha "pl_mail: pl_mail-php-1 publishes no ports" "docker compose up -d php"
elif grep -qv -- '-> 127\.0\.0\.1:' <<<"$ports"; then
  falha "pl_mail: 80/443 published beyond loopback ($(grep -v -- '-> 127\.0\.0\.1:' <<<"$ports" | tr '\n' ' '))" \
    "set host_ip: 127.0.0.1 on the php ports in compose.override.yaml, then docker compose up -d php"
else ok "pl_mail: 80/443 only on 127.0.0.1"; fi

exit $((FALHAS > 0))
