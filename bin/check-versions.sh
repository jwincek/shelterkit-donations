#!/usr/bin/env bash
#
# Verify that every place the plugin records its version and its minimum
# requirements agrees with every other place.
#
# WordPress.org serves the build from the SVN tag named by `Stable tag`, so a
# mismatch between the plugin header and the readme silently ships the wrong
# version — with no error anywhere. Plugin Check also fails a header/readme
# requirement mismatch.
#
# Usage:
#   bin/check-versions.sh              # check internal consistency
#   bin/check-versions.sh v2.1.0       # also require the release tag to match
#
set -uo pipefail

cd "$( dirname "${BASH_SOURCE[0]}" )/.." || exit 1

MAIN_FILE="shelterkit-donations.php"
README="readme.txt"
CHANGELOG="CHANGELOG.md"
POT="languages/shelterkit-donations.pot"

fail=0
err() { printf '  FAIL  %s\n' "$1"; fail=1; }
ok()  { printf '  ok    %s\n' "$1"; }

# --- extract -----------------------------------------------------------------
header_version=$(grep -m1 -E '^ \* Version:' "$MAIN_FILE" \
  | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
const_version=$(grep -m1 "STARTER_SHELTER_VERSION'" "$MAIN_FILE" \
  | sed -E "s/.*'STARTER_SHELTER_VERSION',[[:space:]]*'([^']+)'.*/\1/")
readme_stable=$(grep -m1 -E '^Stable tag:' "$README" \
  | sed -E 's/.*Stable tag:[[:space:]]*//' | tr -d '[:space:]')
# Newest *versioned* changelog heading; "## [Unreleased]" is skipped on purpose.
changelog_version=$(grep -m1 -E '^## \[[0-9]' "$CHANGELOG" \
  | sed -E 's/^## \[([^]]+)\].*/\1/')

header_requires=$(grep -m1 -E '^ \* Requires at least:' "$MAIN_FILE" \
  | sed -E 's/.*Requires at least:[[:space:]]*//' | tr -d '[:space:]')
readme_requires=$(grep -m1 -E '^Requires at least:' "$README" \
  | sed -E 's/.*Requires at least:[[:space:]]*//' | tr -d '[:space:]')
header_php=$(grep -m1 -E '^ \* Requires PHP:' "$MAIN_FILE" \
  | sed -E 's/.*Requires PHP:[[:space:]]*//' | tr -d '[:space:]')
readme_php=$(grep -m1 -E '^Requires PHP:' "$README" \
  | sed -E 's/.*Requires PHP:[[:space:]]*//' | tr -d '[:space:]')
composer_php=$(grep -m1 -E '"php": ">=' composer.json \
  | sed -E 's/.*">=[[:space:]]*([0-9.]+)".*/\1/')

echo "Plugin version: ${header_version}"
echo

# --- version fields ----------------------------------------------------------
[ -n "$header_version" ] || err "could not read Version from $MAIN_FILE"

if [ "$const_version" = "$header_version" ]; then
  ok "STARTER_SHELTER_VERSION matches the plugin header"
else
  err "STARTER_SHELTER_VERSION ($const_version) != plugin header ($header_version)"
fi

if [ "$readme_stable" = "$header_version" ]; then
  ok "readme.txt Stable tag matches the plugin header"
else
  err "readme.txt Stable tag ($readme_stable) != plugin header ($header_version)"
fi

if [ "$changelog_version" = "$header_version" ]; then
  ok "CHANGELOG.md newest release heading matches the plugin header"
else
  err "CHANGELOG.md newest release heading ($changelog_version) != plugin header ($header_version)"
fi

# readme.txt must document the version being shipped.
if grep -qE "^= ${header_version//./\\.} =" "$README"; then
  ok "readme.txt changelog has a section for ${header_version}"
else
  err "readme.txt changelog has no '= ${header_version} =' section"
fi

# The .pot is regenerated per release; CI compares its strings separately.
if [ -f "$POT" ]; then
  # Match the trailing semver rather than the plugin name: hard-coding the
  # name means a rename silently breaks this check instead of failing loudly.
  pot_version=$(grep -m1 'Project-Id-Version:' "$POT" \
    | sed -E 's/.*[[:space:]]([0-9]+\.[0-9]+\.[0-9]+).*/\1/')
  if [ "$pot_version" = "$header_version" ]; then
    ok "languages/*.pot Project-Id-Version matches the plugin header"
  else
    err ".pot Project-Id-Version ($pot_version) != plugin header ($header_version) — regenerate with wp i18n make-pot"
  fi
fi

# --- requirement fields ------------------------------------------------------
if [ "$header_requires" = "$readme_requires" ]; then
  ok "'Requires at least' agrees ($header_requires)"
else
  err "'Requires at least': header ($header_requires) != readme ($readme_requires)"
fi

if [ "$header_php" = "$readme_php" ]; then
  ok "'Requires PHP' agrees ($header_php)"
else
  err "'Requires PHP': header ($header_php) != readme ($readme_php)"
fi

# composer's platform floor decides what the lock file resolves against; if it
# drifts above the header, CI installs packages the plugin's own minimum PHP
# cannot run.
if [ "$composer_php" = "$header_php" ]; then
  ok "composer.json php constraint agrees ($composer_php)"
else
  err "composer.json php constraint ($composer_php) != 'Requires PHP' ($header_php)"
fi

# --- shared ShelterKit profile -----------------------------------------------
# Version negotiation compares the string passed to register(), not the code,
# so a copy whose class says 1.3.0 but registers as 1.2.0 competes as 1.2.0 and
# can lose to an older sibling copy. The registration cannot read the class
# constant: the class is not loaded until negotiation picks a winner (#24).
PROFILE_CLASS="includes/shelterkit/class-shelterkit-profile.php"
profile_class_version=$(grep -m1 -E "public const VERSION = '" "$PROFILE_CLASS" \
  | sed -E "s/.*VERSION = '([^']+)'.*/\1/")
profile_doc_version=$(grep -m1 -E '^ \* @version ' "$PROFILE_CLASS" \
  | sed -E 's/.*@version[[:space:]]*//' | tr -d '[:space:]')
profile_registered=$(grep -m1 -E "ShelterKit_Profile_Versions::register\( '" "$MAIN_FILE" \
  | sed -E "s/.*register\( '([^']+)'.*/\1/")

if [ -z "$profile_class_version" ] || [ -z "$profile_registered" ]; then
  err "could not read the shared profile's VERSION or its register() call"
elif [ "$profile_registered" = "$profile_class_version" ]; then
  ok "shared profile registers as ${profile_registered}, matching ShelterKit_Profile::VERSION"
else
  err "shared profile registers as ${profile_registered} but ShelterKit_Profile::VERSION is ${profile_class_version}"
fi

if [ "$profile_doc_version" = "$profile_class_version" ]; then
  ok "shared profile @version matches ShelterKit_Profile::VERSION"
else
  err "shared profile @version (${profile_doc_version}) != ShelterKit_Profile::VERSION (${profile_class_version})"
fi

# --- optional: release tag ---------------------------------------------------
if [ "$#" -ge 1 ] && [ -n "${1:-}" ]; then
  tag_version="${1#v}"
  if [ "$tag_version" = "$header_version" ]; then
    ok "release tag $1 matches the plugin version"
  else
    err "release tag $1 (=> $tag_version) != plugin version ($header_version)"
  fi
fi

echo
if [ "$fail" -ne 0 ]; then
  echo "Version metadata is inconsistent. Fix the fields above before releasing."
  exit 1
fi

echo "All version metadata is consistent."
