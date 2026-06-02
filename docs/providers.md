# Provider Development

HearMe discovers TTS providers as Drupal services tagged with `hear_me.provider`.

The built-in Piper provider is only one implementation. A provider can call a local service, a private HTTP API, or a cloud TTS API.

## Required Interface

All providers implement `Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface`.

Required methods:

- `synthesize(string $text, string $lang): ?TtsSynthesisResult`: calls the external service and returns raw audio bytes + format metadata. Returns `null` when the backend fails or cannot generate usable audio.
- `getSupportedLanguages(): array`: language codes this provider can handle, for example, `['en', 'uk']`.
- `getLabel(): string`: human-readable name shown in the admin dropdown.
- `getDefaultMimeType(): string`: MIME type for cache lookups, for example, `audio/wav`.
- `getDefaultExtension(): string`: file extension without dot, for example, `wav`.

## Audio Result

Return a `Drupal\hear_me\TtsSynthesisResult` with:

- Raw audio bytes.
- MIME type, for example `audio/wav` or `audio/mpeg`.
- Extension without the dot, for example `wav` or `mp3`.

HearMe uses this metadata for response headers, file cache names, and managed File entities.

## Service Registration

Register the provider in your module's services file:

```yaml
services:
  mymodule.provider.mytts:
    class: Drupal\mymodule\Plugin\TtsProvider\MyTtsProvider
    arguments:
      - '@http_client'
      - '@config.factory'
    tags:
      - { name: hear_me.provider, provider_key: mytts }
```

The `provider_key` tag value is the machine name used in `hear_me.settings` and provider config names.

Clear caches after adding or changing provider services.

### Provider config namespace

Each provider should store its settings in a config object named `hear_me.provider.<provider_key>`. The settings form and `HearMeService` resolve provider config by that naming convention. For example, the Piper provider uses `hear_me.provider.piper`.

**Example: connecting a cloud API**

The module is not tied to self-hosted services. A provider backed by a cloud TTS API (such as Google Cloud TTS, AWS Polly, or ElevenLabs) would look identical from the module's perspective — it just implements the same interface, makes its own HTTP calls inside `synthesize()`, and returns a `TtsSynthesisResult` with the audio bytes.

### Configurable Providers

If the provider has admin settings, also implement `Drupal\hear_me\Plugin\TtsProvider\TtsProviderConfigurableInterface`.

That interface lets the provider add fields to the HearMe settings form and save its own configuration. Methods:

`buildConfigForm(array $form, array $config)`: adds provider-specific fields to the settings form.
`submitConfigForm(array &$form, FormStateInterface $form_state)`: saves those fields to config.

Store provider settings in:

```text
hear_me.provider.<provider_key>
```

For example, Piper stores settings in:

```text
hear_me.provider.piper
```

Add a config schema for every provider config object so Drupal can validate and export it cleanly.

### Provider-specific settings

The lower section of the settings form shows fields specific to the selected provider. These fields change automatically when you switch the **Active TTS Provider** dropdown — no page reload needed. What appears there is entirely up to the provider implementation; it could be an endpoint URL, an API key, a model name, or any other value the provider needs.

If a provider does not implement `TtsProviderConfigurableInterface`, no provider-specific section is shown in the settings form for that provider.

## Switching providers

1. Go to **Administration → Configuration → Media → HearMe TTS**.
2. Change the **Active TTS Provider** dropdown to the desired provider.
3. Fill in the provider-specific settings that appear below.
4. Save. The new provider is active immediately for all subsequent synthesis requests.

Only providers that are registered as tagged services in an enabled module appear in the dropdown. See [Provider system](#provider-system) for how to add one.

## Minimal Provider Skeleton

```php
<?php

namespace Drupal\mymodule\Plugin\TtsProvider;

use Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface;
use Drupal\hear_me\TtsSynthesisResult;

final class ExampleProvider implements TtsProviderInterface {

  public function synthesize(string $text, string $lang): ?TtsSynthesisResult {
    $bytes = $this->callBackend($text, $lang);
    if ($bytes === '') {
      return NULL;
    }

    return new TtsSynthesisResult($bytes, 'audio/mpeg', 'mp3');
  }

  public function getSupportedLanguages(): array {
    return ['en'];
  }

  public function getLabel(): string {
    return 'Example TTS';
  }

  public function getDefaultMimeType(): string {
    return 'audio/mpeg';
  }

  public function getDefaultExtension(): string {
    return 'mp3';
  }

  private function callBackend(string $text, string $lang): string {
    return '';
  }

}
```

## Language handling

The `/hear-me/tts` endpoint validates the requested language against the active provider's supported language codes. It normalises case and underscores, accepts an exact supported code first, then tries the two-letter short code, for example `en-US` to `en`. Unsupported languages return `400 Unsupported language`.

Language values come from these places:

| Flow | Language source |
|---|---|
| Inline `<tts>` playback | The text filter writes `data-lang` on the generated button from the filter language code. If the language is missing or unspecified, it uses the provider's configured **Default Language**. |
| Whole-page, selected text, and selected section playback | The block attaches `drupalSettings.hear_me.default_lang`. It uses the current Drupal interface language short code when the provider supports it, otherwise the provider's configured **Default Language**. |
| Direct API requests | If `lang` is omitted or empty, the endpoint uses the provider's configured **Default Language**. |
| Queue-based pre-generation | New node jobs use the node entity language. If it is unspecified, they use the provider's configured **Default Language**. |

Provider modules should return every code they can synthesise from `getSupportedLanguages()`. Configurable providers should store their fallback language in the `default_lang` key of `hear_me.provider.<provider_key>`.

### Enabling per-node language detection

1. Enable the `language` and `content_translation` core modules.
2. Add languages at **Administration → Configuration → Regional & language → Languages**.
3. Enable language assignment for your content types at **Administration → Configuration → Regional & language → Content language and translation**.
4. Set the **Language** field on each node before saving.

## Cached audio files

Runtime playback files are stored under `private://hear_me/tts/` by default. If **Runtime cache file storage** is changed to public, runtime files are stored at `public://tts/<hash>.<ext>` (typically `sites/default/files/tts/`). If caching is disabled, the source TTL is `0`, or the selected stream wrapper is unavailable, playback still works but the generated audio is returned directly and is not persisted.

Queue-generated entity audio always uses `public://tts/<hash>.<ext>` because it is attached to content as Media.

| Flow | File entity | Media entity | Cleanup behavior |
|---|---|---|---|
| Runtime playback (`inline`, `page`, `selection`, `adhoc`) | Created only when audio is persisted. | No. | Expired entries and entries over runtime file-count/size limits are purged by cron. The settings form can also clear tracked runtime cache entries. |
| Queue pre-generation (`entity`) | Yes. | Yes, using the `hear_me_audio` media type. | Excluded from runtime cache TTL and size cleanup because it is attached to content. |

HearMe cache IDs include:

| Component | Purpose                                                                                                |
|---|--------------------------------------------------------------------------------------------------------|
| Source type | Separates `inline`, `page`, `selection`, `adhoc`, and `entity` requests.                               |
| Storage key | Separates private and public runtime cache files. Queue-generated `entity` audio always uses `public`. |
| Text hash | Separates different normalised text payloads without storing text in the cache ID.                     |
| Language | Separates the same text synthesised in different languages.                                            |
| Provider key | Separates providers.                                                                                   |
| Audio extension | Separates output formats such as `wav` and `mp3`.                                                      |
| Provider configuration hash | Separates audio generated before and after provider settings change.                                   |

Runtime cache entries are purged by cron when they expire or when the configured file-count/total-size limits are exceeded. The settings form also includes **Clear generated runtime audio cache** for environments without Drush.

Provider setting changes create new cache entries instead of reusing stale audio. If a remote model or voice file changes without a Drupal config change, clear the runtime cache from the settings form and regenerate queued media if needed.

To manually clear the runtime audio cache with Drush:

```bash
drush php-eval "\Drupal::service('hear_me.cache_manager')->clearRuntimeCache();"
```

## Security Guidelines

- Do not store API keys in plain config if the target site requires secret management. Use Drupal's key management patterns or environment-specific settings where appropriate.
- Validate provider endpoint URLs and avoid credentials in URLs.
- Set conservative timeouts for remote HTTP calls.
- Log backend failures without logging the full text payload or secret values.
- Respect HearMe's max request size and max text length before calling expensive backends.

## Testing Providers

Recommended coverage for provider modules:

- Unit test language and extension/MIME metadata.
- Kernel test provider service discovery through `hear_me.provider` tag.
- Functional test settings form submit if the provider is configurable.
- Failure-path test for backend errors returning `NULL`.
