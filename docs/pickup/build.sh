#!/usr/bin/env bash
# Сборка PDF материалов pick-01. Запуск: bash docs/pickup/build.sh [имя-без-расширения]
set -euo pipefail
cd "$(dirname "$0")"
CHROME="$(command -v google-chrome || command -v chromium || command -v chromium-browser)"
for src in _src/${1:-*}.src.html; do
  name="$(basename "$src" .src.html)"
  "$CHROME" --headless=new --disable-gpu --no-sandbox --allow-file-access-from-files \
    --no-pdf-header-footer --print-to-pdf="$name.pdf" "file://$PWD/$src" 2>/dev/null
  echo "собран $name.pdf"
done
