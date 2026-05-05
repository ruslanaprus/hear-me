# HearMe

A Drupal 11 accessibility module that adds text-to-speech playback to content pages. It is built around an open provider system — any HTTP-based TTS service, cloud API, or custom backend can be integrated by implementing a single PHP interface. Piper is included as the default provider.

---

## How it works

1. A speaker button (🔊) is rendered next to marked text or as a standalone block.
2. When clicked, the browser POSTs the text and language code to `/hear-me/tts`.
3. Drupal forwards the request to whichever TTS provider is currently active.
4. The synthesised WAV file is returned and played through an `<audio>` element on the page.
5. Synthesised files are cached on disk; identical requests are served from cache without hitting the backend service again.

---

## Architecture

```
Browser
  │  POST /hear-me/tts  { text, lang }
  ▼
HearMeController
  │
  ▼
HearMeService  ──── file cache ────► return cached WAV
  │  (cache miss)
  ▼
Active TTS Provider  (implements TtsProviderInterface)
  │
  ▼
External TTS service / API
  │
  ▼
WAV file  ──►  saved as Drupal Media entity  ──►  returned to browser
```

---

## Requirements

### Drupal modules

| Module | Source | Notes |
|---|---|---|
| `media` | Drupal core | Stores synthesised audio as Media entities |
| `file` | Drupal core | Manages the underlying WAV files |
| `language` *(optional)* | Drupal core | Required for per-node language detection |
| `content_translation` *(optional)* | Drupal core | Required to set a language on individual nodes |

Enable the optional modules if your site has multilingual content — without them all nodes default to the site language.

### External TTS service

The module requires at least one registered TTS provider. The built-in **Piper provider** expects a running [Piper TTS HTTP service](https://github.com/ruslanaprus/piper-tts-service) that accepts `POST` requests with `{ "text": "...", "lang": "..." }` and returns `audio/wav`. Any service with a compatible interface works — self-hosted or cloud-hosted.

See [Provider system](#provider-system) below for how to connect a different service.

### Database

The MariaDB/MySQL connection must use `utf8mb4` to correctly handle non-ASCII content. Ensure `settings.php` includes:

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

---

## Admin settings

**Administration → Configuration → Media → HearMe TTS**
`/admin/config/media/hear-me`

Requires the `administer site configuration` permission.

### Global settings

| Setting | Description |
|---|---|
| **Active TTS Provider** | Which registered provider handles synthesis. Changing this reloads the provider-specific settings section below via AJAX. |
| **Enable file-based caching** | When enabled, synthesised WAV files are stored in `public://tts/` and reused for identical text + language combinations. Disable to always re-synthesise. |

### Provider-specific settings

Each provider contributes its own settings fields to the form. The fields shown below the global settings change automatically when you switch provider. What is shown depends entirely on the active provider's implementation — it could be an API endpoint URL, an API key, a model name, or anything else the provider needs.

The built-in **Piper** provider exposes:

| Setting | Description |
|---|---|
| **Piper Endpoint URL** | Full URL of the TTS service endpoint, e.g. `http://piper-service:5000/tts`. |
| **Supported Language Codes** | Comma-separated list of language codes this provider can handle, e.g. `en, uk`. Determines the options available in the Default Language field and validates language codes at runtime. |
| **Default Language** | Language used when none can be resolved from the page context. |

---

## Provider system

Providers are standard Drupal tagged services. The module discovers them automatically — there is no central registry to update. Adding a provider to any enabled module is enough to make it appear in the admin dropdown.

### `TtsProviderInterface`

Every provider must implement `\Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface`, which requires:

| Method | Purpose |
|---|---|
| `synthesize(string $text, string $lang): ?Media` | Calls the external service, saves the result as a Drupal Media entity, and returns it. |
| `getSupportedLanguages(): array` | Returns the language codes this provider can handle. |
| `getProviderKey(): string` | Unique machine name. Must match the `provider_key` tag in `services.yml`. |
| `getLabel(): string` | Human-readable name shown in the admin dropdown. |
| `buildConfigForm(array $form, array $config): array` | Adds provider-specific fields to the settings form. |
| `submitConfigForm(array &$form, FormStateInterface $form_state): void` | Saves those settings to config. |

### Registering a provider

Create a class that implements the interface, then register it as a service tagged with `hear_me.provider`:

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

After a cache clear (`drush cr`) the provider will appear in the **Active TTS Provider** dropdown.

---

## Adding TTS to content

### Option 1 — Inline text filter

Enable the **TTS Playback Button** text filter (`filter_tts_playback`) on a text format at **Administration → Configuration → Content authoring → Text formats**.

Wrap any passage in `<tts>` tags in the content body:

```html
<tts>This sentence will have a speaker button next to it.</tts>
```

The filter renders this as:

```
[visible text]  🔊  [audio player, hidden until clicked]
```

The language used for synthesis is taken from the node's content language (set via the Language field on the edit form — requires the `language` and `content_translation` modules).

### Option 2 — "Listen to this page" block

Place the **HearMe TTS Block** via **Administration → Structure → Block layout**.

The block renders a single 🔊 **Listen to this page** button and can be placed in any region. It is intended as a page-level control, for example in a header or sidebar.

> **Note:** The block button carries a `data-action="tts-page"` attribute reserved for future full-page synthesis. That feature is not yet implemented — the block is a placeholder UI element.

---

## Asynchronous synthesis (queue)

The module includes a Drupal queue worker (`hear_me_tts`) that processes synthesis jobs during cron. When an article node is created, a job is pushed onto the queue containing the node title, body text, and language. During the next cron run the worker calls the active provider and attaches the resulting Media entity to `field_tts_audio` on the node.

This means heavy synthesis work does not happen in the request cycle, and the queue can be processed by any Drupal-compatible queue backend — the default database queue, or a contributed module such as [Drupal Queue API](https://www.drupal.org/project/queue_api) backed by Redis, RabbitMQ, or another broker.

To trigger the queue manually during development:

```bash
drush queue:run hear_me_tts
```

The queue item payload is a plain array:

```php
[
  'nid'  => 42,        // node ID to attach the result to
  'text' => '...',     // text to synthesise
  'lang' => 'en',      // language code
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

## Language handling

The language sent to the provider is resolved in this order:

1. The `data-lang` attribute on the `<tts>` button — set from the node's content language by the text filter.
2. `drupalSettings.hear_me.default_lang` — resolved from the active Drupal interface language at page load, validated against the provider's supported language codes.
3. The provider's configured **Default Language** as a final fallback.

To enable per-node language detection:

1. Enable the `language` and `content_translation` core modules.
2. Add the required languages at **Administration → Configuration → Regional & language → Languages**.
3. Enable language selection for your content types at **Administration → Configuration → Regional & language → Content language and translation**.
4. Set the **Language** field on each node before saving.

---

## Cached audio files

Synthesised WAV files are stored at `public://tts/<hash>.wav` (typically `sites/default/files/tts/`). The hash is derived from the text, language code, and provider key — so each unique combination is cached separately, and swapping providers or languages will not serve stale audio.

To clear the audio cache:

```bash
drush php-eval "array_map('unlink', glob(\Drupal::service('file_system')->realpath('public://tts') . '/*.wav'));"
```

---

## HTTP endpoint

| Method | Path | Permission | Description |
|---|---|---|---|
| `POST` | `/hear-me/tts` | `access content` | Synthesise text. Accepts JSON `{ "text": "...", "lang": "en" }`. Returns `audio/wav`. |

---

## File structure

```
hear_me/
├── config/
│   ├── install/
│   │   ├── hear_me.settings.yml          — default global settings (active provider, cache flag)
│   │   └── hear_me.provider.piper.yml    — default Piper provider settings
│   └── schema/
│       └── hear_me.schema.yml            — typed config schemas
├── js/
│   └── hear_me.js                        — speaker button click handler
├── src/
│   ├── Controller/HearMeController.php   — POST /hear-me/tts handler
│   ├── Form/HearMeSettingsForm.php       — admin settings form (provider-aware)
│   ├── Service/HearMeService.php         — synthesis orchestrator + file cache
│   └── Plugin/
│       ├── TtsProvider/
│       │   ├── TtsProviderInterface.php  — contract every provider must implement
│       │   └── PiperProvider.php         — built-in Piper HTTP provider
│       ├── Block/HearMeBlock.php         — "Listen to this page" block
│       ├── Field/Formatter/
│       │   └── TtsAudioFormatter.php     — renders audio fields as <audio> elements
│       ├── Filter/FilterTtsPlayback.php  — transforms <tts>…</tts> into speaker buttons
│       └── Queue/HearMeQueueWorker.php   — async cron worker for background synthesis
├── hear_me.info.yml
├── hear_me.libraries.yml
├── hear_me.links.menu.yml
├── hear_me.module                        — install, entity insert, and page attachment hooks
├── hear_me.routing.yml
└── hear_me.services.yml                  — service + provider tag registration
```
