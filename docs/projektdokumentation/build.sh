#!/usr/bin/env bash
# Erzeugt Diagramme und PDF der IT-Projektdokumentation (benötigt nur Docker).
set -euo pipefail
cd "$(dirname "$0")"
ROOT="$(cd ../.. && pwd)"

echo "[1/3] Diagramme rendern ..."
for f in diagramme/*.mmd; do
  name="$(basename "$f" .mmd)"
  docker run --rm -u "$(id -u):$(id -g)" -v "$PWD/diagramme:/data" minlag/mermaid-cli:latest \
    -q -i "/data/$name.mmd" -o "/data/$name.png" -c /data/mermaid-config.json -s 3 -b white
done

echo "[2/3] Render-Image bauen ..."
docker build -q -t lanpa-projektdoku-render build >/dev/null

echo "[3/3] PDF erzeugen ..."
docker run --rm --init --ipc=host -v "$ROOT:/work" lanpa-projektdoku-render
