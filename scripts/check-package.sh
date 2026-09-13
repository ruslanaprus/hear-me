#!/usr/bin/env bash

set -euo pipefail

archive="$(mktemp)"
contents="$(mktemp)"
trap 'rm -f "$archive" "$contents"' EXIT

git archive --worktree-attributes --format=tar HEAD > "$archive"
tar -tf "$archive" > "$contents"

required=(
  CHANGELOG.md
  LICENSE.txt
  README.md
  composer.json
  config/install/hear_me.settings.yml
  config/schema/hear_me.schema.yml
  css/hear_me.css
  hear_me.info.yml
  hear_me.install
  hear_me.libraries.yml
  hear_me.module
  hear_me.permissions.yml
  hear_me.routing.yml
  hear_me.services.yml
  js/hear_me.js
  src/Controller/HearMeController.php
)

for path in "${required[@]}"; do
  if ! grep -Fxq "$path" "$contents"; then
    printf 'Required release file is missing: %s\n' "$path" >&2
    exit 1
  fi
done

forbidden_pattern='(^|/)(\.env($|\.)|\.git(attributes|ignore)?$|\.github/|\.idea/|\.phpunit\.cache/|\.phpstan-cache/|composer\.lock$|phpcs\.xml\.dist$|phpstan(-baseline)?\.neon\.dist$|phpstan-baseline\.neon$|phpunit\.xml\.dist$|scripts/|tests/|vendor/)'
missing_production_files=()

while IFS= read -r path; do
  if [[ "$path" =~ $forbidden_pattern ]]; then
    continue
  fi
  if ! grep -Fxq "$path" "$contents"; then
    missing_production_files+=("$path")
  fi
done < <(git ls-files)

if (( ${#missing_production_files[@]} > 0 )); then
  printf 'Release archive is missing tracked production files:\n' >&2
  printf '  %s\n' "${missing_production_files[@]}" >&2
  exit 1
fi

if grep -Eq "$forbidden_pattern" "$contents"; then
  printf 'Release archive contains development artifacts:\n' >&2
  grep -E "$forbidden_pattern" "$contents" >&2
  exit 1
fi

printf 'Release archive contains required module files and no development artifacts.\n'
