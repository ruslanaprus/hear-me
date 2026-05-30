# Provider Development

HearMe discovers TTS providers as Drupal services tagged with `hear_me.provider`.

The built-in Piper provider is only one implementation. A provider can call a local service, a private HTTP API, or a cloud TTS API.

## Required Interface

All providers implement `Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface`.

Required methods:

- `synthesize(string $text, string $lang): ?TtsSynthesisResult`
- `getSupportedLanguages(): array`
- `getLabel(): string`
- `getDefaultMimeType(): string`
- `getDefaultExtension(): string`

`synthesize()` should return `NULL` when the backend fails or cannot generate usable audio.

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
  mymodule.provider.example:
    class: Drupal\mymodule\Plugin\TtsProvider\ExampleProvider
    arguments:
      - '@http_client'
      - '@config.factory'
      - '@logger.factory'
    tags:
      - { name: hear_me.provider, provider_key: example }
```

The `provider_key` value is the machine name used in `hear_me.settings` and provider config names.

Clear caches after adding or changing provider services.

## Configurable Providers

If the provider has settings, also implement `Drupal\hear_me\Plugin\TtsProvider\TtsProviderConfigurableInterface`.

That interface lets the provider add fields to the HearMe settings form and save its own configuration.

Store provider settings in:

```text
hear_me.provider.<provider_key>
```

For example, Piper stores settings in:

```text
hear_me.provider.piper
```

Add a config schema for every provider config object so Drupal can validate and export it cleanly.

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

## Cache Behavior

HearMe cache IDs include:

- Source type such as `inline`, `page`, or `selection`.
- Runtime cache storage scheme.
- Text hash.
- Language.
- Provider key.
- Audio extension.
- Provider configuration hash.

This means provider settings changes create new cache entries instead of reusing old audio. If the remote model changes without a config change, clear the runtime cache from the settings form.

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
