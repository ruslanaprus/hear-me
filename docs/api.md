# HearMe Public API

HearMe intentionally supports two PHP integration boundaries before its first stable release: TTS provider plugins and the synthesis application service. Other module services and implementation classes are internal unless this document or the provider guide says otherwise.

## Provider Plugin API

A provider module uses these supported types:

- `Drupal\hear_me\Attribute\TtsProvider` for plugin discovery, with a permanent `id` and translated `label`.
- `Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface` for synthesis, supported-language, and file-extension declarations.
- `Drupal\hear_me\TtsSynthesisResult` as the provider's successful synthesis result.
- Drupal's `ContainerFactoryPluginInterface` when the provider injects services.
- Drupal's `ConfigurableInterface` and `PluginFormInterface` when the provider has administrator-editable configuration.

`TtsSynthesisResult` exposes readonly `bytes`, `mimeType`, and `extension` properties, and its MIME type is authoritative for each successful response. Providers return `NULL` when they cannot produce usable audio. The extension is normalized to lowercase ASCII letters and numbers, with `bin` as the fallback.

Provider configuration uses one object per plugin:

```text
hear_me.provider.<plugin_id>
```

The module supplying the provider owns that object's install defaults and complete configuration schema. Keep the plugin ID stable because it is also used by active configuration and cache identity. Do not store credentials directly in exported configuration.

See [TTS provider plugin development](providers.md) for the complete extension process, configuration forms, dependency injection, security requirements, and tests.

## Synthesis Application API

Custom server-side integrations can inject the `hear_me.service` service, implemented by `Drupal\hear_me\Service\HearMeService`. Its supported operations are deliberately limited to:

```php
getAudio(string $text, string $lang, string $source = 'adhoc', ?string $providerId = NULL): ?TtsAudioResult
synthesize(string $text, string $lang, ?string $providerId = NULL): ?MediaInterface
buildCacheToken(string $text, string $lang, string $source, ?string $providerId = NULL): string
getTrustedRuntimeSource(string $text, string $lang, string $source, ?string $cacheToken, ?string $providerId = NULL): string
```

### Runtime synthesis

`getAudio()` returns a `Drupal\hear_me\TtsAudioResult` or `NULL` when the text exceeds the configured maximum TTS text length, the selected provider is unavailable, or the provider returns no usable result. The same text limit applies to `synthesize()`. Runtime sources are `inline`, `page`, `selection`, and `adhoc`; unknown values normalize to `adhoc`. Passing a provider ID keeps a multi-step operation tied to that provider identity instead of resolving the active provider again. Missing required active-provider configuration raises a `RuntimeException`, and unexpected provider, cache, or storage exceptions are not converted to `NULL`.

`TtsAudioResult` exposes readonly `bytes`, `mimeType`, `extension`, `uri`, and `fid` properties. `uri` and `fid` are `NULL` when the audio was not persisted. Callers must use the returned MIME type and extension rather than assuming WAV.

### Persistent synthesis

`synthesize()` forces generated audio into HearMe's entity-audio storage and returns a `MediaInterface` suitable for server-side attachment workflows. Success guarantees that the persistent `entity` provenance row is durably linked to the exact File ID referenced by the returned Media. It returns `NULL` when the provider returns no usable synthesis result. It throws `Drupal\hear_me\Exception\PersistentSynthesisUnavailableException` when provider discovery, synthesis locking, persistent File/provenance storage, or Media persistence is temporarily unavailable. These failures are never converted to a successful Media result through URI inference.

### Inline cache-source tokens

`buildCacheToken()` and `getTrustedRuntimeSource()` are a paired API for rendering and later verifying an `inline` runtime source. The token is opaque and bound to the normalized text, language, source, provider identity, provider configuration, and Drupal private key. Do not parse or persist it as durable data. An invalid or mismatched inline token is downgraded to `adhoc`.

## Trusted-Caller Requirement

The application service is a server-side coordination API. It does not apply the `/hear-me/tts` route permission, CSRF requirement, request-size validation, Flood API limits, or per-user quotas. A custom caller must perform authorization, input validation, resource limiting, and output access control appropriate to its entry point.

Use the Drupal route for browser playback so HearMe's existing endpoint controls remain in force.

## Internal Implementation

The active-provider resolver, cache manager, Media factory, node attacher, audio-field provisioner, endpoint validation result, settings-form builders, and similar services are implementation details. Their public PHP methods exist for internal collaboration and are not supported extension points.

The removed pre-release `buildTtsUri()`, `getAudioBytes()`, and file-helper service are not compatibility contracts. Use `getAudio()` when format metadata is required and let HearMe own cache URI construction.
