#!/bin/bash
SSL_DIR="$(dirname "$0")/ssl"
mkdir -p "$SSL_DIR"

openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout "$SSL_DIR/stdozor.key" \
    -out "$SSL_DIR/stdozor.crt" \
    -subj "/C=CZ/ST=Prague/L=Prague/O=Stdozor Dev/OU=Development/CN=localhost"

echo "SSL certifikát vygenerován v: $SSL_DIR"
