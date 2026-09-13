#!/usr/bin/env bash

set -euo pipefail

release_tags="$(git tag --list)"
if [[ -z "$release_tags" ]]; then
  printf 'No releases exist; release-fixture update tests are not applicable yet.\n'
  exit 0
fi

update_tests="$(git ls-files 'tests/src/**/*Update*Test.php')"
if [[ -z "$update_tests" ]]; then
  printf 'Release tags require a committed *Update*Test.php under tests/src/.\n' >&2
  exit 1
fi

references_fixture=false
while IFS= read -r update_test; do
  if grep -Eq 'fixtures/releases' "$update_test"; then
    references_fixture=true
    break
  fi
done <<< "$update_tests"

if [[ "$references_fixture" != true ]]; then
  printf 'An update-path test must load fixtures from tests/fixtures/releases/.\n' >&2
  exit 1
fi

while IFS= read -r tag; do
  fixture_path="tests/fixtures/releases/$tag"
  fixture_files="$(git ls-files "$fixture_path/**")"
  if [[ -z "$fixture_files" ]]; then
    printf 'Release tag %s requires committed files under %s/.\n' "$tag" "$fixture_path" >&2
    exit 1
  fi
done <<< "$release_tags"

printf 'Every release tag has a committed fixture and update-path coverage.\n'
