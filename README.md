# HearMe

HearMe is a Drupal 11 accessibility module that adds text-to-speech (TTS) playback controls to content pages. It provides inline speaker buttons for marked text, a floating "Listen to this page" block, selected text playback, section selection playback, runtime audio caching. Clicking a speaker button synthesises the text and plays it back through an `<audio>` element — no page reload required.

The module is built around an **open provider system**: any HTTP-based TTS service, cloud API, or custom backend can be plugged in by implementing a single PHP interface. HearMe includes a built-in Piper-compatible HTTP adapter, but it does not include or run Piper itself.

---

## How it works

1. A speaker button (🔊) appears next to text marked with `<tts>...</tts>` or as a floating HearMe block for whole-page playback. The block has a "Select section to listen" mode with mouse and keyboard support.
2. Clicking the button POSTs the text and language code to `/hear-me/tts`.
3. Drupal forwards the request to whichever TTS provider is currently active.
4. The synthesised audio is returned and played through an `<audio>` element on the page.
5. Runtime audio files are cached in private storage by default.

```
Browser
  │  click 🔊
  │  POST /hear-me/tts  { text, lang, source }
  ▼
HearMeController  ──  input validation
  │
  ▼
HearMeService  ──── file cache hit? ──► return cached audio ──► <audio> element
  │  (cache miss)
  ▼
Active TTS Provider  (implements TtsProviderInterface)
  │  HTTP call to external service / API
  ▼
audio bytes  ──►  optionally saved as File + cache metadata  ──►  returned to browser  ──►  <audio> element
```

---

## Requirements

- Drupal 11.
- PHP 8.3 or later.
- Drupal core `file` module: Manages runtime cache and pre-generated audio files.
- Drupal core `media` module: Stores queue-generated/pre-generated audio as Media entities.
- Drupal core `language` module (optional): Required for per-node language detection.
- Drupal core `content_translation` (optional): Required to assign a language to individual nodes.
- A reachable TTS backend. A Piper-compatible HTTP service is supported by the built-in adapter.

Enable the optional modules only if your site has multilingual content. Without them all nodes default to the site language.

Private files are recommended because runtime playback can include selected text or authenticated page content.

## Installation

After the project is available on Drupal.org:

```bash
composer require drupal/hear_me
drush en hear_me -y
drush cr
```

If Drush is not available, enable the module from **Administration > Extend** and clear caches from **Administration > Configuration > Development > Performance**.

See [docs/installation.md](docs/installation.md) for full installation and setup steps.

## Adding TTS to content

There are two ways to surface the speaker button on a page. They can be used independently or together:

### Option 1 — Inline text filter

Enable **TTS Playback Button** on a text format, allow the `<tts>` tag:

**Administration → Configuration → Content authoring → Text formats and editors**

Then add markup like this to content:

```html
<tts>Being a cat isn’t just about whiskers and naps—it’s a philosophy, a lifestyle, and a subtle art form</tts>
```

The language follows the rendered text language and is validated by the TTS endpoint. To enable per-node language detection, see [Language handling](docs/providers.md#language-handling).

### Option 2 — "Listen to this page" block


Place the **HearMe TTS Block** via **Administration → Structure → Block layout**.

The block renders a 🔊 **Listen to this page** button that can be placed in any region. The block can read the page, read selected browser text, or let the user choose a section with **Select section to listen**. When clicked, it:

1. Uses the currently highlighted text first, if the user has selected any text on the page.
2. Otherwise reads the page in a content-first mode (article/main text), excluding comments, menu, and sidebar by default.
3. Lets users opt in to **Include comments**, **Include sidebar**, and **Include menu** via checkboxes under the block.
4. Sends the cleaned text to `/hear-me/tts` and plays the returned audio through an `<audio controls>` element.

The block also renders a **Select section to listen** control:

- Turns on an inspect mode where users can hover and click a specific section to read aloud.
- Works with keyboard navigation too (`Arrow keys` to move, `Enter` to select, `Esc` to cancel).
- Limits default selectable targets to content-first areas, with comments/sidebar/menu available only when their opt-in checkboxes are enabled.

The block is language-aware: it resolves the current interface language against the provider's supported language codes before sending the request.

## Configuration

Settings are available to users with **Administer HearMe** at:

```text
/admin/config/media/hear-me
```

Important settings include:

- Setup status checks for provider, queue worker, media type, audio field, file storage, cron, and anonymous access.
- Active provider.
- Runtime cache storage and retention.
- Rate limits and quotas.
- Max request size and max text length.
- Queue bundles and audio attachment field.
- Generated audio replacement and manual audio overwrite protection.
- Audio field setup for selected content types.
- Existing content backfill through the settings form or Drush.
- Provider-specific settings such as the Piper-compatible endpoint and languages.

See [Global settings](docs/installation.md#global-settings) for the full settings table and defaults.

### Existing Content Backfill

After installing HearMe on a site that already has content, use **Queue existing content** on the settings page to add background audio-generation jobs for configured content types. If Drush is installed, the same backfill is available with `drush hear-me:queue-existing`.

By default, regenerated queue audio can replace existing HearMe-generated media so attached audio stays current after content changes. Manually selected or unknown audio is protected by default and is not overwritten unless **Overwrite manually selected audio** is enabled.

## Documentation

- [Installation](docs/installation.md)
- [Piper HTTP adapter](docs/piper.md)
- [Provider development](docs/providers.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Changelog](CHANGELOG.md)

## Provider System

Providers are Drupal services tagged with `hear_me.provider`. Custom modules can register additional providers without changing HearMe code.

See [docs/providers.md](docs/providers.md) for interfaces, service tags, and configurable provider guidance.

### External TTS service

The module requires at least one registered TTS provider. The built-in **Piper HTTP adapter** connects to an external [Piper-compatible TTS HTTP service](https://github.com/ruslanaprus/piper-tts-service) that accepts `POST /tts` with `{ "text": "...", "lang": "..." }` and returns `audio/wav`. HearMe does not include Piper binaries, voices, containers, or service code; site owners must provide an endpoint reachable from Drupal. The Piper-compatible service has no format selection parameter — WAV is its fixed output format. Other providers are free to return any audio format (MP3, OGG, etc.); the provider interface declares the MIME type and file extension so the module handles caching correctly regardless of format. Any service with a compatible request/response contract works — self-hosted, containerised, or cloud-hosted.

See [Provider system](#provider-system) to connect a different service.

### Database

The MariaDB/MySQL connection must use `utf8mb4` to handle non-ASCII content correctly. Ensure `settings.php` includes:

```php
$databases['default']['default'] = [
  // ...
  'charset'   => 'utf8mb4',
  'collation' => 'utf8mb4_general_ci',
];
```

---

## Security Notes

The `/hear-me/tts` endpoint requires the **Use TTS playback** permission (`use tts playback`) and a valid CSRF request header token.

Runtime responses from `/hear-me/tts` are sent with `Cache-Control: private, no-store, max-age=0, must-revalidate` so browsers and intermediaries do not store click-generated audio responses. Generated media files attached to content use normal Drupal file/media handling.

Grant **Use TTS playback** to Anonymous users only after reviewing rate limits, quotas, provider capacity, and whether generated runtime audio may contain private or user-selected text.

Grant **Administer HearMe** (`administer hear me`) to trusted site builders who should configure providers, cache storage, rate limits, queue settings, setup checks, and generated audio field setup without receiving the broad **Administer site configuration** permission.

## License

HearMe is licensed under GPL-2.0-or-later. The legal license text is in [LICENSE.txt](LICENSE.txt).

In plain terms: you may use, study, modify, and redistribute this module under the GPL. If you distribute modified versions, they must remain GPL-compatible. The module is provided without warranty.
