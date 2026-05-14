# HearMe

A Drupal 11 accessibility module that adds text-to-speech (TTS) playback to content pages. Clicking a speaker button synthesises the text and plays it back through an `<audio>` element — no page reload required.

The module is built around an **open provider system**: any HTTP-based TTS service, cloud API, or custom backend can be plugged in by implementing a single PHP interface. Piper is included as the default provider, but it is not a requirement.

---

## How it works

1. A speaker button (🔊) appears next to marked text (inline) or as a standalone block on the page.
2. Clicking the button POSTs the text and language code to `/hear-me/tts`.
3. Drupal forwards the request to whichever TTS provider is currently active.
4. The synthesised audio is returned and played through an `<audio>` element on the page.
5. Audio files are cached on disk — identical text + language combinations are served from cache without calling the backend again.

```
Browser
  │  click 🔊
  │  POST /hear-me/tts  { text, lang }
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
audio bytes  ──►  saved as File + Media entity  ──►  returned to browser  ──►  <audio> element
```

---

## Requirements

### Drupal core modules

| Module | Source | Notes |
|---|---|---|
| `media` | Drupal core | Stores synthesised audio as Media entities |
| `file` | Drupal core | Manages the underlying audio files |
| `language` *(optional)* | Drupal core | Required for per-node language detection |
| `content_translation` *(optional)* | Drupal core | Required to assign a language to individual nodes |

Enable the optional modules only if your site has multilingual content. Without them all nodes default to the site language.

### External TTS service

The module requires at least one registered TTS provider. The built-in **Piper provider** connects to a [Piper TTS HTTP service](https://github.com/ruslanaprus/piper-tts-service) that accepts `POST /tts` with `{ "text": "...", "lang": "..." }` and always returns `audio/wav`. The Piper service has no format selection parameter — WAV is its fixed output format. Other providers are free to return any audio format (MP3, OGG, etc.); the provider interface declares the MIME type and file extension so the module handles caching correctly regardless of format. Any service with a compatible request/response contract works — self-hosted, containerised, or cloud-hosted.

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

## Installation

1. Place the module in `web/modules/custom/hear_me/`.
2. Enable it:
   ```bash
   drush en hear_me -y
   ```
3. Clear caches:
   ```bash
   drush cr
   ```

On first enable, the module creates the `public://tts/` directory and installs the `hear_me_audio` media type along with its file field.

---

## Admin settings

**Administration → Configuration → Media → HearMe TTS**
`/admin/config/media/hear-me`

Requires the `administer site configuration` permission.

### Global settings

| Setting | Default | Description |
|---|---|---|
| **Active TTS Provider** | `piper` | Which registered provider handles synthesis. Switching providers reloads the provider-specific settings section via AJAX. |
| **Enable file-based caching** | `true` | When enabled, synthesised audio is stored in `public://tts/` and reused for identical text + language requests. Disable to always re-synthesise. |
| **TTS Audio Field** | `field_tts_audio` | The node field used by the queue worker to attach pre-generated audio to nodes. |
| **Queue Bundles** | *(empty)* | Node bundle machine names that trigger background synthesis when a new node is created. Empty means none. |
| **Max Request Bytes** | `32768` | Maximum accepted request body size in bytes. |
| **Max Text Length** | `5000` | Maximum accepted text length in characters. |

### Provider-specific settings

The lower section of the form shows fields specific to the selected provider. These fields change automatically when you switch the **Active TTS Provider** dropdown — no page reload needed. What appears there is entirely up to the provider implementation; it could be an endpoint URL, an API key, a model name, or any other value the provider needs.

The built-in **Piper** provider exposes:

| Setting | Description |
|---|---|
| **Piper Endpoint URL** | Full URL of the TTS service, e.g. `http://piper-service:5000/tts`. |
| **Supported Language Codes** | Comma-separated list of language codes the service can handle, e.g. `en, uk`. Must match the voice models installed on the Piper service. |
| **Default Language** | Language used when no language can be resolved from the page context. |

### Switching providers

1. Go to **Administration → Configuration → Media → HearMe TTS**.
2. Change the **Active TTS Provider** dropdown to the desired provider.
3. Fill in the provider-specific settings that appear below.
4. Save. The new provider is active immediately for all subsequent synthesis requests.

Only providers that are registered as tagged services in an enabled module appear in the dropdown. See [Provider system](#provider-system) for how to add one.

---

## Adding TTS to content

There are two ways to surface the speaker button on a page. They can be used independently or together:

### Option 1 — Inline text filter

Enable the **TTS Playback Button** text filter (`filter_tts_playback`) on a text format:

**Administration → Configuration → Content authoring → Text formats and editors**

Wrap any passage in `<tts>` tags in the body field:

```html
<tts>This sentence will have a speaker button next to it.</tts>
```

The filter transforms this into:

```
[visible text]  🔊  [hidden audio player, shown after first click]
```

Clicking 🔊 sends the text to `/hear-me/tts` and plays the audio response through the `<audio controls>` element that appears inline. A status message (`aria-live="polite"`) is shown while audio is loading and cleared once playback starts.

The language is taken from the node's content language. To enable per-node language detection, see [Language handling](#language-handling).

### Option 2 — "Listen to this page" block

Place the **HearMe TTS Block** via **Administration → Structure → Block layout**.

The block renders a 🔊 **Listen to this page** button that can be placed in any region. When clicked, it:

1. Takes the text content of the `<main>` element (or `<body>` if `<main>` is absent).
2. Strips navigation, contextual links, and module-specific elements.
3. POSTs the cleaned text to `/hear-me/tts`.
4. Plays the returned audio through an `<audio controls>` element inserted after the button.

The block is language-aware: it resolves the current interface language against the provider's supported language codes before sending the request.

---

## Provider system

Providers are standard Drupal tagged services. The module discovers them automatically via `!tagged_iterator` — there is no central registry to update. Any enabled module can contribute a provider and it will appear in the admin dropdown after a cache clear.

### Interfaces

**`TtsProviderInterface`** — required for all providers:

| Method | Return type | Purpose |
|---|---|---|
| `synthesize(string $text, string $lang)` | `?TtsSynthesisResult` | Calls the external service and returns raw audio bytes + format metadata. Returns `null` on failure. |
| `getSupportedLanguages()` | `array` | Language codes this provider can handle, e.g. `['en', 'uk']`. |
| `getLabel()` | `string` | Human-readable name shown in the admin dropdown. |
| `getDefaultMimeType()` | `string` | MIME type for cache lookups, e.g. `audio/wav`. |
| `getDefaultExtension()` | `string` | File extension without dot, e.g. `wav`. |

**`TtsProviderConfigurableInterface`** — optional, implement this if the provider has admin settings:

| Method | Purpose |
|---|---|
| `buildConfigForm(array $form, array $config)` | Adds provider-specific fields to the settings form. |
| `submitConfigForm(array &$form, FormStateInterface $form_state)` | Saves those fields to config. |

If a provider does not implement `TtsProviderConfigurableInterface`, no provider-specific section is shown in the settings form for that provider.

### Registering a provider

Create a class that implements `TtsProviderInterface`, then register it as a service tagged `hear_me.provider`:

```yaml
# mymodule/mymodule.services.yml

services:
  mymodule.provider.mytts:
    class: Drupal\mymodule\Plugin\TtsProvider\MyTtsProvider
    arguments:
      - '@http_client'
      - '@config.factory'
    tags:
      - { name: hear_me.provider, provider_key: mytts }
```

The `provider_key` tag value is the machine name used in `hear_me.settings` and in cache file URIs. After `drush cr` the provider appears in the **Active TTS Provider** dropdown.

### Provider config namespace

Each provider should store its settings in a config object named `hear_me.provider.<provider_key>`. The settings form and `HearMeService` resolve provider config by that naming convention. For example, the Piper provider uses `hear_me.provider.piper`.

### Example: connecting a cloud API

The module is not tied to self-hosted services. A provider backed by a cloud TTS API (such as Google Cloud TTS, AWS Polly, or ElevenLabs) would look identical from the module's perspective — it just implements the same interface, makes its own HTTP calls inside `synthesize()`, and returns a `TtsSynthesisResult` with the audio bytes.

---

## Language handling

The language sent to the provider is resolved in this order:

1. `data-lang` on the `<tts>` button — set from the node's content language by the text filter.
2. `drupalSettings.hear_me.default_lang` — resolved from the current Drupal interface language at page load, validated against the provider's supported codes.
3. The provider's configured **Default Language** as the final fallback.

### Enabling per-node language detection

1. Enable the `language` and `content_translation` core modules.
2. Add languages at **Administration → Configuration → Regional & language → Languages**.
3. Enable language assignment for your content types at **Administration → Configuration → Regional & language → Content language and translation**.
4. Set the **Language** field on each node before saving.

---

## Cached audio files

Synthesised files are stored at `public://tts/<hash>.<ext>` (typically `sites/default/files/tts/`). The filename is the MD5 hash of `text + lang + providerKey + extension`, so each unique combination is cached independently. Swapping providers or languages never serves stale audio.

Synthesised files are also tracked as Drupal `file` and `media` entities (bundle `hear_me_audio`).

To manually clear the audio cache:

```bash
drush php-eval "array_map('unlink', glob(\Drupal::service('file_system')->realpath('public://tts') . '/*'));"
```

---

## Asynchronous synthesis (queue)

The module includes a Drupal queue worker (`hear_me_tts`) that processes synthesis jobs during cron. No content bundles are enrolled by default — enrolment is opt-in via the **Queue Bundles** setting.

When a new node is created and its bundle is listed in **Queue Bundles**, a job is pushed onto the queue containing the node title, body text, and language. During the next cron run the worker calls the active provider and attaches the resulting Media entity to the field named in **TTS Audio Field** on the node.

This keeps heavy synthesis work out of the request cycle. The queue can be processed by any Drupal-compatible queue backend: the default database queue, or a contributed module such as those backed by Redis, RabbitMQ, Amazon SQS, or any other broker.

To process the queue manually during development:

```bash
drush queue:run hear_me_tts
```

### Queue item payload

```php
[
  'nid'  => 42,     // node ID to attach the result to
  'text' => '...',  // text to synthesise
  'lang' => 'en',   // language code
]
```

Other modules can push items onto the same queue to trigger synthesis from their own context:

```php
\Drupal::queue('hear_me_tts')->createItem([
  'nid'  => $node->id(),
  'text' => $node->body->value,
  'lang' => $node->language()->getId(),
]);
```

---

## HTTP endpoint

| Method | Path | Permission | CSRF | Description |
|---|---|---|---|---|
| `POST` | `/hear-me/tts` | `use tts playback` | required | Accepts JSON `{ "text": "...", "lang": "en" }`. Returns audio. |
| `GET` | `/admin/config/media/hear-me` | `administer site configuration` | — | Admin settings form. |

The TTS endpoint requires a `X-CSRF-Token` header. The JavaScript library fetches this automatically from the `system.csrftoken` route and caches it for the lifetime of the page.

---

## Permissions

| Permission | Description |
|---|---|
| `use tts playback` | Required to call `POST /hear-me/tts`. Grant to any role that should be able to trigger synthesis (typically Authenticated user). |
| `administer site configuration` | Required to access the HearMe settings form. |

---

## Uninstallation

```bash
drush pmu hear_me -y
```

On uninstall the module removes:
- All synthesised audio files under `public://tts/`.
- All `file` and `media` entities that reference those files.
- The `hear_me_audio` media type and its field definitions.
- All `hear_me.*` config objects.

Audio files uploaded by editors or created by other modules are not touched.

---

## File structure

```
hear_me/
├── config/
│   ├── install/
│   │   ├── hear_me.settings.yml                           — default global settings
│   │   ├── hear_me.provider.piper.yml                     — default Piper provider settings
│   │   ├── media.type.hear_me_audio.yml                   — hear_me_audio media type
│   │   ├── field.storage.media.field_hear_me_audio_file.yml
│   │   └── field.field.media.hear_me_audio.field_hear_me_audio_file.yml
│   └── schema/
│       └── hear_me.schema.yml                             — typed config schemas
├── js/
│   └── hear_me.js                                         — speaker button + block click handler
├── src/
│   ├── Controller/
│   │   └── HearMeController.php                           — POST /hear-me/tts handler
│   ├── Form/
│   │   └── HearMeSettingsForm.php                         — admin settings form (AJAX provider switching)
│   ├── Service/
│   │   ├── HearMeService.php                              — orchestrator: cache, synthesis, media entities
│   │   ├── HearMeInputValidator.php                       — request body validation + text normalisation
│   │   ├── TtsFileHelper.php                              — URI builder: md5(text+lang+provider+ext)
│   │   └── TtsFileHelperInterface.php                     — interface + TTS_URI_BASE constant
│   ├── Plugin/
│   │   ├── TtsProvider/
│   │   │   ├── TtsProviderInterface.php                   — contract every provider must implement
│   │   │   ├── TtsProviderConfigurableInterface.php       — optional contract for providers with settings
│   │   │   └── PiperProvider.php                          — built-in Piper HTTP provider
│   │   ├── Block/
│   │   │   └── HearMeBlock.php                            — "Listen to this page" block
│   │   ├── Field/Formatter/
│   │   │   └── TtsAudioFormatter.php                      — renders file fields as <audio> elements
│   │   ├── Filter/
│   │   │   └── FilterTtsPlayback.php                      — transforms <tts>…</tts> into speaker buttons
│   │   └── Queue/
│   │       └── HearMeQueueWorker.php                      — cron queue worker for background synthesis
│   ├── TtsAudioResult.php                                 — DTO: audio bytes + MIME + extension (HTTP response)
│   ├── TtsSynthesisResult.php                             — DTO: raw synthesis output from provider
│   └── TtsInputValidationResult.php                       — DTO: input validation outcome
├── hear_me.info.yml
├── hear_me.libraries.yml
├── hear_me.links.menu.yml
├── hear_me.module                                         — install / uninstall / entity insert hooks
├── hear_me.permissions.yml
├── hear_me.routing.yml
└── hear_me.services.yml                                   — service definitions + provider tag registration
```
