# Installation

This guide covers installing HearMe as a Drupal 11 contributed module package.

## Requirements

- Drupal 11.
- PHP 8.3 or later.
- Drupal core `file` module.
- Drupal core `media` module.
- Private files configured if you want the default private runtime cache to persist generated playback audio.
- At least one TTS provider service. The module ships with a Piper provider.

The module can run without Drupal's optional multilingual modules. Enable `language` and `content_translation` only if your site needs per-content language assignment.

## Install With Composer

After the project is available on Drupal.org, install it from the Drupal package repository:

```bash
composer require drupal/hear_me
```

Then enable the module from **Administration > Extend** or with Drush if available:

```bash
drush en hear_me -y
drush cr
```

## Install Without Composer

Download or clone the module into your Drupal codebase:

```text
web/modules/contrib/hear_me
```

For local development before a Drupal.org release, `web/modules/custom/hear_me` is also fine.

Enable the module from **Administration > Extend**. If the site does not have Drush, clear caches from **Administration > Configuration > Development > Performance**.

## Configure Private Files

Runtime playback cache files are stored in `private://hear_me/tts/` by default. If private files are not configured, playback still works, but generated runtime audio is not persisted until private storage is available or the runtime cache storage is changed to public.

In `settings.php`, configure a private files path outside the public web root:

```php
$settings['file_private_path'] = '../private';
```

Use an absolute path or a path that is correct for your hosting environment.

## Configure HearMe

Go to **Administration > Configuration > Media > HearMe TTS**.

Recommended first-pass settings:

- Keep **Active TTS Provider** set to `piper` unless another provider module is installed.
- Keep **Runtime cache file storage** set to `private` for authenticated or selected text playback.
- Confirm the Piper endpoint URL is reachable from the Drupal server, not just from your browser.
- Keep strict rate limits if granting playback to Anonymous users.

## Permissions

Grant **Use TTS playback** to roles that should be able to trigger synthesis.

This permission is restricted because each request can call an external TTS service and consume CPU, memory, network, and disk cache resources. Grant it to Anonymous users only after rate limits, quotas, and cache privacy are reviewed.

## Enable Inline Playback

Go to **Administration > Configuration > Content authoring > Text formats and editors**.

Edit the text format your editors use, then:

- Enable **TTS Playback Button**.
- Allow the `<tts>` tag if the format also uses **Limit allowed HTML tags and correct faulty HTML**.
- Save the text format.

Editors can then write:

```html
<tts>This sentence will get a speaker button.</tts>
```

## Place The Block

Go to **Administration > Structure > Block layout**.

Place **HearMe TTS Block** in a theme region. After saving, content pages should show the floating HearMe controls. The block supports:

- Whole-page playback.
- Playback of currently selected browser text.
- Section selection mode.
- Optional inclusion of comments, sidebars, and menus.

## Queue-Based Pre-Generation

The module includes the `hear_me_tts` queue worker for cron-based pre-generation. No bundles are queued by default.

To enable queue generation:

- Add a Media reference field to the target node bundle.
- Set **TTS Audio Field** to that field's machine name.
- Add bundle machine names to **Queue Bundles**.
- Ensure cron runs regularly.

The queue worker creates or reuses generated audio media and attaches it to the configured node field.

## Uninstall

Before uninstalling, remove dependencies that Drupal reports on the uninstall page. For example, if the HearMe text filter is enabled on a text format, disable it first.

On uninstall, HearMe removes:

- Runtime cache rows from `hear_me_audio_cache`.
- Generated audio files under `private://hear_me/tts/` and `public://tts/`.
- Managed File entities for generated HearMe audio.
- Generated `hear_me_audio` media entities.
- Module-owned media type and field config.
- Module-owned simple config.

Editor-uploaded files outside HearMe-owned audio directories are skipped.
