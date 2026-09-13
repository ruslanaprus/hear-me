#!/usr/bin/env bash

set -euo pipefail

event_pattern='Test Triggered (PHP )?Deprecation'
known_trigger_pattern='issue triggered by (first-party code|third-party code|test code|PHP runtime|PHPUnit) calling into (first-party code|third-party code|test code|PHP runtime|PHPUnit)'
direct_or_self_pattern="${event_pattern} .*issue triggered by (first-party code|test code) calling into|${event_pattern} .*calling into (first-party code|test code)"
unknown_module_pattern="${event_pattern} .*unknown if issue.* in .*/modules/(custom|contrib)/hear_me/"

check_events() {
  local events="$1"
  local unsupported

  if [[ ! -f "$events" ]]; then
    printf 'PHPUnit event log does not exist: %s\n' "$events" >&2
    return 1
  fi

  unsupported="$(grep -E "$event_pattern" "$events" | grep -Ev "$known_trigger_pattern|unknown if issue" || true)"
  if [[ -n "$unsupported" ]]; then
    printf 'Unsupported PHPUnit deprecation event format:\n%s\n' "$unsupported" >&2
    return 1
  fi

  if grep -Eq "$direct_or_self_pattern|$unknown_module_pattern" "$events"; then
    printf 'Self or direct HearMe deprecation detected:\n' >&2
    grep -E "$direct_or_self_pattern|$unknown_module_pattern" "$events" >&2
    return 1
  fi

  printf 'No self or direct HearMe deprecations detected.\n'
}

if [[ "${1:-}" == '--self-test' ]]; then
  events="$(mktemp)"
  trap 'rm -f "$events"' EXIT

  printf '%s\n' 'Test Triggered Deprecation (Example, issue triggered by third-party code calling into third-party code) in /vendor/example.php:1' > "$events"
  check_events "$events" >/dev/null

  printf '%s\n' 'Test Triggered Deprecation (Example, issue triggered by first-party code calling into third-party code) in /vendor/example.php:1' > "$events"
  if check_events "$events" >/dev/null 2>&1; then
    printf 'Direct-deprecation classifier self-test failed.\n' >&2
    exit 1
  fi

  printf '%s\n' 'Test Triggered PHP Deprecation (Example, issue triggered by third-party code calling into first-party code) in /modules/custom/hear_me/src/Example.php:1' > "$events"
  if check_events "$events" >/dev/null 2>&1; then
    printf 'Self-deprecation classifier self-test failed.\n' >&2
    exit 1
  fi

  printf 'Deprecation classifier self-test passed.\n'
  exit 0
fi

if [[ $# -ne 1 ]]; then
  printf 'Usage: %s <phpunit-event-log>\n' "$0" >&2
  exit 2
fi

check_events "$1"
