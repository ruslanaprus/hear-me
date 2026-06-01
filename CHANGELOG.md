# Changelog

All notable changes to HearMe will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows Drupal.org release practices.

## Unreleased

### Added

- Drupal 11 text-to-speech playback endpoint at `/hear-me/tts`.
- Inline `<tts>...</tts>` text filter with generated speaker controls.
- Floating "Listen to this page" block with whole-page, selected-text, and section selection playback.
- Provider system based on tagged Drupal services.
- Built-in Piper HTTP provider.
- Runtime audio cache metadata table with private/public file storage support.
- Flood API rate limits and daily/monthly quotas for synthesis requests.
- Queue worker `hear_me_tts` for cron-based entity audio pre-generation.
- Settings form action to create the generated audio media reference field on selected content types.
- Admin settings form for provider, cache, rate limit, queue, and request size configuration.

### Changed

- Lifecycle hooks live in `hear_me.install` for Drupal install/uninstall discovery.
- Queue worker lives under `Plugin\QueueWorker` for Drupal queue worker discovery.
- Runtime playback cache defaults to private files.

### Security

- TTS endpoint requires the `use tts playback` permission and a CSRF request header token.
- Anonymous playback is intentionally a restricted permission because synthesis can consume server resources.

### Notes

- No stable Drupal.org release has been tagged yet.
