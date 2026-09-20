# Changelog

All notable changes to HearMe will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows Drupal.org release practices.

## Unreleased

### Added

- Drupal 11 text-to-speech playback endpoint at `/hear-me/tts`.
- Inline `<tts>...</tts>` text filter with generated speaker controls.
- Floating "Listen to this page" block with whole-page, selected-text, and section selection playback.
- Attribute-discovered TTS provider plugin system with provider-owned configuration forms.
- Built-in Piper-compatible HTTP adapter.
- Runtime audio cache metadata table with private/public file storage support.
- Flood API rate limits and daily/monthly quotas for synthesis requests.
- Queue worker `hear_me_tts` for cron-based entity audio pre-generation.
- Deduplicated cleanup queue with cron publication repair for provenance-backed generated Media blocked by temporary lifecycle lock contention.
- Per-content-type queue source field selection for generated entity audio.
- Predictable generated-audio node attachment behavior with field validation and documented node save side effects.
- Settings form action to create the generated audio media reference field on selected content types.
- Settings form and Drush backfill actions to queue existing content for audio pre-generation.
- Setup status panel for provider, queue worker, media type, audio field, file storage, cron, and anonymous permission checks.
- Module-specific `administer hear me` permission for delegated HearMe configuration access.
- Admin settings form for provider, cache, rate limit, queue, and request size configuration.
- Characterization coverage for provider resolution, generated-audio attachment decisions, audio-field provisioning, and the existing-content backfill workflow.
- Automated PHPStan/`phpstan-drupal`, Composer audit, direct-deprecation, PHP 8.3/8.5, release-fixture, and package-export quality gates.
- Conservative uninstall validation that preserves persistent Media/File content and requires administrators to remove or migrate HearMe Audio media first.

### Changed

- Persistent synthesis now succeeds only with durable `entity` provenance linked to the exact File ID used by Media, retries File/provenance/Media persistence failures, rolls back unowned failed-attempt files while preserving adopted files, rejects incomplete cache hits without URI-based ownership inference, and separates public source eligibility from provider readiness so provider or persistence outages alone do not retract valid attached audio.
- Formatted queue source items now run through their stored Drupal text formats as an anonymous visitor before plain-text normalization and hashing. Filtered-out values cannot reach synthesis, empty/unavailable formats and filter failures fail closed without raw-text fallback, and plain string fields keep their direct path.
- Simplified queue ownership to one durable generation token and a module-owned key-value collection, removed attempt leases, separated queued/duplicate/failed publication outcomes, and added cron repair for retained backend-publication failures.
- Reviewed and froze the intended first-release queue, generated-Media, Piper security, uninstall, and optional Drush contracts; unresolved release blockers are documented for separate verification gates.

- Lifecycle hooks live in `hear_me.install` for Drupal install/uninstall discovery.
- Queue worker lives under `Plugin\QueueWorker` for Drupal queue worker discovery.
- Runtime playback cache defaults to private files.
- Active provider selection and metadata are coordinated by an internal resolver, while provider plugins remain the supported extension API.
- Existing-content backfill captures one active provider identity and stops with a restart message if that provider changes between Batch API chunks.
- Queue workers delegate node Media attachment and replacement-policy decisions to a focused internal service instead of the synthesis orchestrator.
- Persistent generated-audio File/Media lookup and creation are handled by a focused internal factory that returns `MediaInterface`.
- New generated Media records an installed synthesis language as its entity language and safely falls back to language-neutral metadata for unknown provider language identifiers.
- Simplified how HearMe sets up audio fields internally without changing the administrator workflow or existing fields.
- Organized settings-form construction into private section builders without changing the administrator workflow or submitted form structure.
- Narrowed the pre-release synthesis coordinator to runtime synthesis, persistent synthesis, and inline cache-source token operations; removed unused URI and raw-byte facades and documented the supported PHP API.
- Removed the unused provider default-MIME method before publication; synthesis results remain the authoritative MIME source.
- Queue-generated audio is limited to published nodes and source fields available to anonymous visitors; queue payloads retain only node identity, content hash, and an opaque generation token, and lifecycle publication waits for the node transaction to commit. Workers revalidate ownership, current source, and access before attachment, while confirmed provider failures retain a three-failure budget.
- Generated-audio lifecycle updates honor both generated-replacement and manual-overwrite settings while always retracting generated public audio from ineligible content.
- Expired or evicted runtime cache entries stop serving immediately, while Files adopted by other Drupal components are preserved and never overwritten during regeneration.
- Generated-media replacement uses cache provenance instead of public directory names, preserving editor uploads under `public://tts/`.
- Generated public audio is detached when a node becomes definitively ineligible or its public source/language changes under the replacement policy. Provider readiness failures preserve otherwise valid audio. Media/File deletion still requires D10.6 revision-safe reference checks.
- The Piper adapter bounds non-empty `audio/wav` reads, fails closed on unresolved hostnames, validates resolved endpoint addresses, blocks metadata infrastructure destinations, and redacts transport failures. The D10.1 review found that effective cURL pinning and response validation still require D10.8 correction and integration testing.
- Piper language settings preserve exact external voice-registry keys while matching administrator input across case and underscore/hyphen variants; D10.8 must remove the pre-release language-tag-shaped restriction and verify bounded opaque registry keys.
- The TTS endpoint now requires a JSON media type, uses a bounded request-body read, and rejects structured values where strings are required.
- The optional Drush command uses attribute discovery and no longer supports queueing unpublished content; Drush 13.7 minimum-version discovery and execution remain a pre-release verification blocker.

### Fixed

- Cleanup queue worker dependency serialization remains compatible with the supported PHP 8.3 minimum.

### Security

- TTS endpoint requires the `use tts playback` permission and a CSRF request header token.
- Anonymous playback is intentionally a restricted permission because synthesis can consume server resources.
- Module development requires `composer/composer` `^2.10.3`.
- CI rejects new direct module deprecations while continuing to report indirect Drupal core and dependency deprecations.
- Pre-release update hooks were removed so the first tagged release starts from one authoritative install schema and configuration baseline.

### Notes

- No stable Drupal.org release has been tagged yet.
