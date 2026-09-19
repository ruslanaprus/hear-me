# TTS Provider Plugin Development

This guide explains how to connect a text-to-speech backend to HearMe by creating a Drupal TTS provider plugin.

A provider plugin is the integration layer between HearMe and a TTS backend. HearMe supplies normalised text and a language code. The plugin calls its backend and returns audio bytes with their MIME type and file extension.

## How HearMe Uses A Provider

HearMe discovers the available provider plugins and creates configured instances through Drupal's plugin manager. Plugin construction must remain lightweight: open network connections and perform expensive work only when synthesis is requested.

Provider selection and metadata are coordinated by an internal resolver. Provider modules should depend only on the documented plugin attribute, interface, configuration convention, and result object; the resolver and `HearMeService` are not provider extension APIs.

The supported provider extension API is `#[TtsProvider]`, `TtsProviderInterface`, `TtsSynthesisResult`, the `hear_me.provider.<plugin_id>` configuration convention, and the standard Drupal plugin construction and configuration-form interfaces described below. HearMe's separate server-side synthesis application API is documented in [HearMe public API](api.md).

For a synthesis operation, HearMe:

1. Reads the active plugin ID from `hear_me.settings`.
2. Loads configuration from `hear_me.provider.<plugin_id>`.
3. Selects the active provider instance.
4. Uses the provider's language and format metadata.
5. Calls `synthesize($text, $lang)` when audio must be generated.
6. Sends or stores the returned audio according to the playback or queue workflow.

The plugin does not need to manage HearMe routes, access checks, global endpoint limits, cache files, File entities, Media entities, or queue workers.

Browser requests to `/hear-me/tts` pass through HearMe's endpoint validation. Queue workers and setup checks call synthesis through other trusted application paths. A provider must therefore apply its own backend-specific language, text, payload, response-size, timeout, and cost limits rather than assuming every caller passed through the browser endpoint validator.

## Integration Checklist

1. Create a Drupal module that depends on HearMe.
2. Add a class under `src/Plugin/TtsProvider`.
3. Add the `#[TtsProvider]` attribute and implement `TtsProviderInterface`.
4. Add provider install configuration and complete schema.
5. Inject the backend client and implement bounded, validated synthesis.
6. Implement `PluginFormInterface` if administrators need provider settings.
7. Enable the module, rebuild caches, and select the provider in HearMe.
8. Test discovery, configuration, backend failures, and browser playback.

## Provider Types

Choose the simplest type that fits the backend:

- **Fixed provider:** the endpoint and supported languages are defined by code or injected services. Extend `PluginBase`.
- **Configurable provider:** site administrators need provider-specific settings in the HearMe form. Extend `ConfigurablePluginBase` and implement `PluginFormInterface`.

Both types implement `TtsProviderInterface` and use the `#[TtsProvider]` attribute.

## Required Files

A provider normally lives in its own custom or contributed Drupal module:

```text
mymodule/
├── config/
│   ├── install/
│   │   └── hear_me.provider.example.yml
│   └── schema/
│       └── mymodule.schema.yml
├── src/
│   └── Plugin/
│       └── TtsProvider/
│           └── ExampleProvider.php
└── mymodule.info.yml
```

Add a dependency on HearMe in `mymodule.info.yml`:

```yaml
name: 'Example TTS Provider'
type: module
description: 'Connects HearMe to the Example TTS backend.'
package: 'Text to speech'
core_version_requirement: ^11
dependencies:
  - hear_me:hear_me
```

## The Provider Attribute

Place provider classes under `src/Plugin/TtsProvider` and add the `TtsProvider` attribute:

```php
#[TtsProvider(
  id: 'example',
  label: new TranslatableMarkup('Example TTS'),
)]
```

- `id` is the permanent machine name of the provider. Use lowercase letters, numbers, and underscores.
- `label` is the translated name shown in the **Active TTS Provider** field.

The plugin ID is also used in configuration names, cache identity, and saved active-provider settings. Choose it carefully and keep it stable.

After adding or renaming a provider class, rebuild Drupal caches so discovery is refreshed:

```bash
drush cr
```

Without Drush, use **Administration > Configuration > Development > Performance > Clear all caches**.

## The Provider Interface

Every provider implements `Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface`.

| Method | Responsibility |
|---|---|
| `synthesize(string $text, string $lang): ?TtsSynthesisResult` | Generate audio. Return `NULL` when the backend cannot produce usable audio. |
| `getSupportedLanguages(): array` | Return language codes accepted by the backend, such as `['en', 'uk']`. |
| `getDefaultExtension(): string` | Return the normal extension without a dot, such as `mp3`. HearMe uses it when preparing cache filenames before synthesis. |

Keep successful result formats consistent with the default extension. In particular, a result extension must match `getDefaultExtension()` for persisted and cached workflows.

## Minimal Provider Example

This example connects to a fixed HTTPS backend and uses Drupal's HTTP client through dependency injection:

```php
<?php

declare(strict_types=1);

namespace Drupal\mymodule\Plugin\TtsProvider;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hear_me\Attribute\TtsProvider;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface;
use Drupal\hear_me\TtsSynthesisResult;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[TtsProvider(
  id: 'example',
  label: new TranslatableMarkup('Example TTS'),
)]
final class ExampleProvider extends PluginBase implements TtsProviderInterface, ContainerFactoryPluginInterface {

  private const ENDPOINT = 'https://tts.example.com/v1/synthesize';

  private const MAX_AUDIO_BYTES = 10485760;

  private LoggerInterface $logger;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $loggerFactory->get('mymodule');
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('logger.factory'),
    );
  }

  public function synthesize(string $text, string $lang): ?TtsSynthesisResult {
    try {
      $response = $this->httpClient->request('POST', self::ENDPOINT, [
        'json' => [
          'text' => $text,
          'lang' => $lang,
        ],
        'connect_timeout' => 5,
        'timeout' => 30,
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'stream' => TRUE,
        'headers' => [
          'Accept' => 'audio/mpeg',
        ],
      ]);
    }
    catch (GuzzleException $exception) {
      $this->logger->warning('Example TTS request failed with @type.', [
        '@type' => get_debug_type($exception),
      ]);
      return NULL;
    }

    $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
    $body = $response->getBody();
    if ($response->getStatusCode() !== 200 || $contentType !== 'audio/mpeg') {
      $body->close();
      $this->logger->warning('Example TTS returned an invalid audio response.');
      return NULL;
    }

    $bytes = '';
    $complete = FALSE;
    try {
      while (!$body->eof() && strlen($bytes) <= self::MAX_AUDIO_BYTES) {
        $chunk = $body->read(min(8192, self::MAX_AUDIO_BYTES + 1 - strlen($bytes)));
        if ($chunk === '') {
          break;
        }
        $bytes .= $chunk;
      }
      $complete = $body->eof();
    }
    catch (\RuntimeException) {
      $this->logger->warning('Example TTS audio response could not be read.');
      return NULL;
    }
    finally {
      $body->close();
    }
    if (
      $bytes === ''
      || strlen($bytes) > self::MAX_AUDIO_BYTES
      || !$complete
    ) {
      $this->logger->warning('Example TTS returned an invalid audio response.');
      return NULL;
    }

    return new TtsSynthesisResult($bytes, 'audio/mpeg', 'mp3');
  }

  public function getSupportedLanguages(): array {
    return ['en', 'uk'];
  }

  public function getDefaultExtension(): string {
    return 'mp3';
  }

}
```

`ContainerFactoryPluginInterface` is required only when the plugin needs services from Drupal's container. Keep the standard `$configuration`, `$plugin_id`, and `$plugin_definition` constructor arguments before injected dependencies.

## Provider Configuration

HearMe uses one configuration object per plugin:

```text
hear_me.provider.<plugin_id>
```

For the `example` plugin, the name is `hear_me.provider.example`.

Every provider needs a `default_lang` value, even when it has no settings form. HearMe uses it when a request, page, or content entity does not supply a usable language.

Create `config/install/hear_me.provider.example.yml`:

```yaml
default_lang: en
```

Define every key in the provider module's configuration schema:

```yaml
hear_me.provider.example:
  type: config_object
  label: 'Example TTS provider settings'
  mapping:
    default_lang:
      type: string
      label: 'Default language code'
```

The module that supplies the provider owns its install configuration and schema.

## Adding Provider Settings To HearMe

Use a configurable provider when administrators need to set a voice, model, supported languages, or similar values.

The provider must:

1. Extend `Drupal\Core\Plugin\ConfigurablePluginBase`.
2. Implement `Drupal\Core\Plugin\PluginFormInterface`.
3. Build, validate, and submit its provider-specific fields.
4. Call `setConfiguration()` with the complete normalized configuration during submission.

HearMe embeds the plugin form under `provider_settings` and saves the resulting configuration to `hear_me.provider.<plugin_id>`.

### Class Declaration

Import `FormStateInterface`, `ConfigurablePluginBase`, `PluginFormInterface`, and `StringTranslationTrait`, then add the trait to the provider class:

```php
final class ExampleProvider extends ConfigurablePluginBase implements TtsProviderInterface, ContainerFactoryPluginInterface, PluginFormInterface {

  use StringTranslationTrait;
```

Relevant imports:

```php
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ConfigurablePluginBase;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
```

The constructor and `create()` method follow the same dependency-injection pattern as the minimal example.

### Build The Settings Form

```php
public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
  $form['voice'] = [
    '#type' => 'textfield',
    '#title' => $this->t('Voice'),
    '#default_value' => $this->configuration['voice'] ?? 'standard',
    '#required' => TRUE,
  ];
  $form['supported_langs'] = [
    '#type' => 'textfield',
    '#title' => $this->t('Supported language codes'),
    '#default_value' => implode(', ', $this->configuration['supported_langs'] ?? ['en']),
    '#description' => $this->t('Comma-separated values, for example: en, uk.'),
    '#required' => TRUE,
  ];
  $form['default_lang'] = [
    '#type' => 'textfield',
    '#title' => $this->t('Default language code'),
    '#default_value' => $this->configuration['default_lang'] ?? 'en',
    '#required' => TRUE,
  ];
  return $form;
}
```

HearMe passes a `SubformState` as the `FormStateInterface`. During validation and submission, read values relative to the provider form:

```php
$voice = $form_state->getValue('voice');
```

Do not use `provider_settings` as an additional value prefix inside the plugin.

On the initial build, Form API has not assigned `#parents` and `#array_parents` yet. If the initial form structure depends on those properties or submitted subform values, return an element with a `#process` callback and build the dependent elements there, as described by Drupal's `PluginFormInterface` contract. Forms that use only instance configuration for initial defaults can build fields directly.

### Validate Settings

```php
public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
  $supported = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) $form_state->getValue('supported_langs')),
  )));
  $default = trim((string) $form_state->getValue('default_lang'));

  if (!in_array($default, $supported, TRUE)) {
    $form_state->setErrorByName('default_lang', $this->t('The default language must be included in the supported languages.'));
  }
}
```

Validate voice names, model names, language codes, and any other backend-specific values before saving them.

### Store Normalized Instance Configuration

```php
public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
  $supported = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) $form_state->getValue('supported_langs')),
  )));

  $this->setConfiguration([
    'voice' => trim((string) $form_state->getValue('voice')),
    'supported_langs' => $supported,
    'default_lang' => trim((string) $form_state->getValue('default_lang')),
  ]);
}
```

The provider updates its instance configuration. HearMe performs the configuration save.

### Install Defaults And Schema

Create complete defaults:

```yaml
voice: standard
supported_langs:
  - en
default_lang: en
```

Extend the schema to match:

```yaml
hear_me.provider.example:
  type: config_object
  label: 'Example TTS provider settings'
  mapping:
    voice:
      type: string
      label: 'Voice identifier'
    supported_langs:
      type: sequence
      label: 'Supported language codes'
      sequence:
        type: string
        label: 'Language code'
    default_lang:
      type: string
      label: 'Default language code'
```

Configuration schema must describe every stored key. Do not add secrets to install configuration or exported active configuration.

## Runtime Overrides And Secrets

Runtime plugin instances receive effective Drupal configuration, including overrides from `settings.php`. The settings form reads and saves active configuration without exposing or copying overridden values.

An environment-specific override can use:

```php
$config['hear_me.provider.example']['voice'] = 'environment-specific-voice';
```

For credentials and API tokens, prefer a Key module reference, platform secret storage, or an injected secret resolver. Resolve the secret only when making the backend request.

Never:

- Put real credentials in install configuration, schema examples, tests, or documentation.
- Render secrets in the provider settings form.
- Include credentials, authorization headers, synthesis text, or full sensitive URLs in logs.

HearMe's cache identity includes a hash of stored provider configuration. After changing only an overridden endpoint, voice, model, or secret, clear the generated runtime audio cache and regenerate queued media when necessary.

## Language Handling

Return every language code the backend accepts from `getSupportedLanguages()`.

For browser requests to `/hear-me/tts`, HearMe normalizes case and underscores, accepts an exact supported value first, and then tries its two-letter form. For example, `en-US` can fall back to `en` when `en` is supported. Providers should still reject unsupported languages defensively because queue and setup paths do not use the browser endpoint validator.

Use consistent language codes in:

- `getSupportedLanguages()`
- `supported_langs` configuration, when configurable
- `default_lang` configuration
- Requests sent to the backend

## Audio And Error Handling

A successful provider result contains:

```php
new TtsSynthesisResult($bytes, 'audio/mpeg', 'mp3');
```

Provider implementations should:

- Enforce backend-specific text and payload limits on every synthesis path.
- Use bounded connection and request timeouts.
- Bound the audio response size before reading the complete body into memory.
- Disable redirects unless they are explicitly required and validated.
- Verify the HTTP status, response MIME type, and non-empty audio body.
- Return `NULL` for expected backend failures instead of exposing them to the user as uncaught exceptions.
- Log enough information to diagnose the backend without logging text or secrets.
- Keep the declared MIME type and extension consistent with the returned bytes.

If administrators can configure a URL, treat it as a server-side request target. Prefer an explicit host allowlist. At minimum, validate schemes, credentials, fragments, metadata-service addresses, localhost, and private or reserved IP literals. If hostnames can resolve to private networks, account for DNS resolution and rebinding at the network or client boundary as well.

## Enable And Select The Provider

After the provider module is enabled and caches are rebuilt:

1. Go to **Administration > Configuration > Media > HearMe TTS**.
2. Select the provider under **Active TTS Provider**.
3. Complete its provider-specific settings, if present.
4. Save the form.
5. Use **Test provider connection**.
6. Test browser playback and any enabled queue-generation workflow.

Only plugins supplied by enabled modules and discovered successfully appear in the provider list.

## Testing A Provider Module

Recommended coverage:

- **Kernel discovery test:** enable the provider module, clear discovery, assert the plugin ID, label, and class through `plugin.manager.hear_me.tts_provider`, and create an instance.
- **Unit or kernel synthesis test:** verify dependency injection, request options, language values, success, timeout/failure handling, MIME type, and extension.
- **Configuration schema test:** validate every install-config key against the provider module's schema.
- **Functional settings test:** verify field parents, accepted and rejected values, normalization, persistence, and unchanged configuration after validation errors.
- **AJAX or browser test:** switch providers and verify the provider settings section rebuilds without a page reload.
- **Integration test:** use Drupal's `/hear-me/tts` endpoint and confirm playable audio, expected cache headers, and no page reload.

Tests should use a fake HTTP response or deterministic test backend. They should not require production credentials, paid requests, or real voice models.

## Troubleshooting

### The provider does not appear

- Confirm the provider module is enabled.
- Confirm the class is under `src/Plugin/TtsProvider`.
- Confirm the namespace matches the module and directory.
- Confirm the `#[TtsProvider]` attribute and plugin ID are valid.
- Confirm the class implements `TtsProviderInterface`.
- Rebuild Drupal caches.

### The settings form is empty

- Confirm the provider implements both `ConfigurableInterface` and `PluginFormInterface`.
- Confirm `buildConfigurationForm()` returns the form array.
- Read submitted values relative to the provided `SubformState`.

### Settings do not save

- Confirm `submitConfigurationForm()` calls `setConfiguration()` with the complete configuration array.
- Confirm every stored key has configuration schema.
- Check form validation errors and Drupal logs.

### Synthesis returns no audio

- Confirm the selected language is returned by `getSupportedLanguages()`.
- Confirm the effective endpoint and environment overrides are correct.
- Confirm Drupal can reach the backend from the server or container network.
- Confirm the backend returns the expected audio MIME type and non-empty bytes.
- Review Drupal logs for a redacted provider error.

For a concrete bundled implementation, see `Drupal\hear_me\Plugin\TtsProvider\PiperProvider` and the provider tests. Apply the validation, response bounds, privacy controls, and backend-specific hardening required by your own integration.
