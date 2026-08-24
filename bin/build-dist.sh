#!/usr/bin/env bash
#
# Build the distributable plugin package: a single directory named for the
# slug, containing only runtime files.
#
# .distignore is the single source of truth for what is excluded, so this
# script, the Plugin Check CI job, and the WordPress.org deploy all ship
# exactly the same file list.
#
# The leak guard at the end turns packaging into a regression test: the build
# fails if a development file reaches the output, or if a file the plugin needs
# at runtime goes missing.
#
# Usage:
#   bin/build-dist.sh [output-dir]     # default: build/
#
set -euo pipefail

cd "$( dirname "${BASH_SOURCE[0]}" )/.." || exit 1

SLUG="shelterkit-donations"
OUT_DIR="${1:-build}"
DEST="${OUT_DIR}/${SLUG}"

rm -rf "$OUT_DIR"
mkdir -p "$DEST"

# Strip comments and blank lines from .distignore; rsync reads the rest as
# exclude patterns.
grep -vE '^\s*(#|$)' .distignore > "${OUT_DIR}/.rsync-excludes"

rsync -a \
  --exclude-from="${OUT_DIR}/.rsync-excludes" \
  --exclude="/${OUT_DIR}" \
  ./ "${DEST}/"

rm -f "${OUT_DIR}/.rsync-excludes"

# --- leak guard --------------------------------------------------------------
fail=0

# Nothing may ship that is not on this list. An allowlist rather than a list of
# known-bad names: the deny-list version of this guard silently passed
# assets-src/ and CONTRIBUTING.md, because both were added to the repository
# after the guard was written. Anything new now has to be declared here, which
# is a decision rather than an oversight.
ALLOWED_TOP="LICENSE readme.txt shelterkit-donations.php uninstall.php \
             assets blocks config includes languages templates"

while IFS= read -r entry; do
  name=$( basename "$entry" )
  case " $ALLOWED_TOP " in
    *" $name "*) ;;
    *) echo "  LEAK    ${name}  (not in the build's allowlist)"; fail=1 ;;
  esac
done < <( find "$DEST" -mindepth 1 -maxdepth 1 )

# ...and nothing the plugin needs at runtime may go missing. A .distignore
# pattern that is slightly too broad silently ships a plugin that fatals on
# activation, and that is not visible by reading the file list.
for required in \
  "shelterkit-donations.php" \
  "uninstall.php" \
  "readme.txt" \
  "LICENSE" \
  "includes" \
  "blocks" \
  "templates" \
  "config" \
  "assets" \
  "languages/shelterkit-donations.pot"
do
  if [ ! -e "${DEST}/${required}" ]; then
    echo "  MISSING ${required}"
    fail=1
  fi
done

# Every block must arrive with its block.json, or the block silently
# disappears from the inserter on a site that installs the built package.
block_count=$(find blocks -mindepth 2 -maxdepth 2 -name block.json | wc -l | tr -d ' ')
built_count=$(find "${DEST}/blocks" -mindepth 2 -maxdepth 2 -name block.json | wc -l | tr -d ' ')
if [ "$block_count" != "$built_count" ]; then
  echo "  MISSING block.json: source has ${block_count}, build has ${built_count}"
  fail=1
fi

if [ "$fail" -ne 0 ]; then
  echo
  echo "Build failed the leak guard. Fix .distignore before releasing."
  exit 1
fi

echo "Built ${DEST}"
echo "  $(find "$DEST" -type f | wc -l | tr -d ' ') files, $(du -sh "$DEST" | cut -f1)"
