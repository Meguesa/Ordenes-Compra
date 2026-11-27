#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
dest="$root/assets/vendor/odc-scan-v1"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
pack() {
  local name="$1" target="$2" archive
  mkdir -p "$tmp/$target"
  archive="$(cd "$tmp" && npm pack --ignore-scripts --silent "$name" | tail -n 1)"
  tar -xzf "$tmp/$archive" -C "$tmp/$target" --strip-components=1
}
pack pdfjs-dist@6.5.438 pdf
pack tesseract.js@7.0.0 tess
pack tesseract.js-core@7.0.0 core
pack @tesseract.js-data/spa@1.0.0 spa
pack @tesseract.js-data/eng@1.0.0 eng
mkdir -p "$dest/pdf" "$dest/ocr/core" "$dest/ocr/lang" "$dest/licenses"
cp "$tmp/pdf/legacy/build/pdf.min.mjs" "$dest/pdf/pdf.js"
cp "$tmp/pdf/legacy/build/pdf.worker.min.mjs" "$dest/pdf/pdf.worker.js"
for dir in cmaps standard_fonts wasm iccs; do
  if [ -d "$tmp/pdf/$dir" ]; then cp -r "$tmp/pdf/$dir" "$dest/pdf/"; fi
done
cp "$tmp/tess/dist/tesseract.min.js" "$tmp/tess/dist/worker.min.js" "$dest/ocr/"
cp "$tmp/core"/tesseract-core*.wasm.js "$dest/ocr/core/"
cp "$tmp/spa/4.0.0_best_int/spa.traineddata.gz" "$tmp/eng/4.0.0_best_int/eng.traineddata.gz" "$dest/ocr/lang/"
for pkg in pdf tess core spa eng; do
  for file in "$tmp/$pkg"/LICENSE* "$tmp/$pkg"/NOTICE*; do
    if [ -f "$file" ]; then cp "$file" "$dest/licenses/$pkg-$(basename "$file")"; fi
  done
done
test -s "$dest/pdf/pdf.js"
test -s "$dest/pdf/pdf.worker.js"
test -s "$dest/ocr/worker.min.js"
test -s "$dest/ocr/core/tesseract-core-lstm.wasm.js"
test -s "$dest/ocr/lang/spa.traineddata.gz"
test -s "$dest/ocr/lang/eng.traineddata.gz"
echo "Escaner ODC: recursos locales preparados."
