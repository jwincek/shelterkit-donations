#!/usr/bin/env bash
#
# Verify the WordPress.org screenshots line up with their captions.
#
# `screenshot-N.png` binds to the Nth line of the readme's `== Screenshots ==`
# section. The pairing is positional — there is no filename matching — so a
# gap in the numbering or a caption without an image fails silently on the
# listing page: captions attach to the wrong image, or disappear.
#
# Usage:
#   bin/check-screenshots.sh
#
set -uo pipefail

cd "$( dirname "${BASH_SOURCE[0]}" )/.." || exit 1

README="readme.txt"
ASSETS=".wordpress-org"

fail=0
err() { printf '  FAIL  %s\n' "$1"; fail=1; }
ok()  { printf '  ok    %s\n' "$1"; }

# Caption lines: "1. Some text" between == Screenshots == and the next section.
captions=$(awk '/^== Screenshots ==/{f=1;next} /^== /{f=0} f && /^[0-9]+\./' "$README")
caption_count=$(printf '%s' "$captions" | grep -c . || true)

# Captions must be numbered 1..N with no gaps, in order.
expected=1
while IFS= read -r line; do
  [ -n "$line" ] || continue
  num=${line%%.*}
  if [ "$num" != "$expected" ]; then
    err "caption numbering: expected ${expected}. but found '${line:0:40}...'"
  fi
  expected=$(( expected + 1 ))
done <<< "$captions"

shot_count=$(find "$ASSETS" -maxdepth 1 -name 'screenshot-*.png' 2>/dev/null | wc -l | tr -d ' ')

echo "readme captions: ${caption_count}"
echo "screenshot files: ${shot_count}"
echo

if [ "$shot_count" -eq 0 ]; then
  err "no screenshot-N.png in ${ASSETS}/ — the listing page will show none"
  echo
  echo "Generate drafts with bin/capture-screenshots.js, then copy the keepers"
  echo "into ${ASSETS}/ numbered to match the readme captions."
  exit 1
fi

if [ "$caption_count" = "$shot_count" ]; then
  ok "caption count matches screenshot count (${caption_count})"
else
  err "readme has ${caption_count} captions but ${ASSETS}/ has ${shot_count} screenshots"
fi

# Every 1..N must exist as a file, with no gaps.
i=1
while [ "$i" -le "$caption_count" ]; do
  if [ -f "${ASSETS}/screenshot-${i}.png" ]; then
    ok "screenshot-${i}.png present"
  else
    err "screenshot-${i}.png missing — caption ${i} will attach to the wrong image"
  fi
  i=$(( i + 1 ))
done

echo
if [ "$fail" -ne 0 ]; then
  echo "Screenshots and captions are out of step."
  exit 1
fi
echo "Screenshots and captions agree."
