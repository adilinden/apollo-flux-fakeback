#!/bin/sh
# Generate a 10-year self-signed certificate for the backend TLS listener (:443).
# Run once on the Docker host before `docker compose up -d`.
#
#   ./make-cert.sh [common-name]        default: apollo-flux.knet.ca
#
# Writes web/tls/flux.crt and web/tls/flux.key. Refuses to overwrite existing
# files; delete them first to regenerate. The private key stays on this host:
# copy only flux.crt to web-proxy1 if you want Caddy to pin it.
set -eu
cd "$(dirname "$0")"

CN="${1:-apollo-flux.knet.ca}"
CRT=web/tls/flux.crt
KEY=web/tls/flux.key

mkdir -p web/tls
if [ -s "$CRT" ] || [ -s "$KEY" ]; then
    echo "Refusing to overwrite existing $CRT / $KEY (delete them to regenerate)." >&2
    exit 1
fi

openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
    -keyout "$KEY" -out "$CRT" \
    -subj "/CN=$CN" \
    -addext "subjectAltName=DNS:$CN"

chmod 600 "$KEY"
chmod 644 "$CRT"

openssl x509 -in "$CRT" -noout -subject -enddate -fingerprint -sha256
