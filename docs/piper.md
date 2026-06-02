# Piper HTTP Adapter

HearMe includes a provider adapter for a Piper-compatible HTTP TTS service. It does not include or run Piper, voice models, containers, or service code.

Piper is a local neural text-to-speech engine. The adapter expects an external HTTP service that accepts JSON text/language input and returns WAV audio bytes.

## API Contract

The built-in adapter sends:

```http
POST /tts
Content-Type: application/json
```

```json
{
  "text": "How to be a cat",
  "lang": "en"
}
```

The service must return:

```http
Content-Type: audio/wav
```

The built-in adapter treats Piper-compatible output as WAV only. Other providers can return other formats by implementing the provider interface and reporting a different MIME type and extension.

## Docker Compose Example

In a local Docker Compose network, Drupal can call Piper by service name:

```yaml
services:
  piper-service:
    build: ./piper-service
    container_name: piper-service
    ports:
      - "5000:5000"
    environment:
      DEFAULT_LANG: ${PIPER_DEFAULT_LANG:-en}
      VOICES_PATH: ${PIPER_VOICES_PATH:-/app/voices/voices.json}
    volumes:
      - ./piper-service/voices:/app/voices
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:5000/health"]
      interval: 30s
      timeout: 10s
      retries: 3
```

In HearMe settings, use the endpoint reachable from the Drupal container:

```text
http://piper-service:5000/tts
```

Use a URL that is reachable from the Drupal server/container. `http://localhost:5000/tts` only works if Piper runs in the same container or same host network namespace as Drupal.

Release installs leave the endpoint empty by default. Configure it in the UI or with an environment-specific Drupal config override, for example:

```php
$config['hear_me.provider.piper']['endpoint'] = getenv('HEAR_ME_PIPER_ENDPOINT') ?: 'https://tts.example.com/tts';
```

## Provider Settings

Go to **Administration > Configuration > Media > HearMe TTS**.

Piper-compatible adapter settings:

- **Piper Endpoint URL**: absolute URL to the `/tts` endpoint.
- **Supported Language Codes**: comma-separated list of language codes the service can handle, for example, `en, uk`. Must match the voice models installed on the external service.
- **Default Language**: fallback language used when no language can be resolved from the page context.

The endpoint URL must not contain usernames or passwords. If authentication is required, put Piper behind an internal proxy and implement authentication there, or create a custom provider that sends the required headers securely.

## Voice Registry

Piper supports a large number of languages and voices. The full catalogue is available at [huggingface.co/rhasspy/piper-voices](https://huggingface.co/rhasspy/piper-voices).
Each voice consists of two files:
- `<name>.onnx` — the model weights
- `<name>.onnx.json` — the model config
Download both and place them in the `voices/` directory.

A Piper service commonly uses a `voices.json` file to map language codes to model files:

```json
{
  "en": {
    "name": "English",
    "model": "/app/voices/en_GB-alan-medium.onnx",
    "config": "/app/voices/en_GB-alan-medium.onnx.json"
  },
  "uk": {
    "name": "Ukrainian",
    "model": "/app/voices/uk_UA-ukrainian_tts-medium.onnx",
    "config": "/app/voices/uk_UA-ukrainian_tts-medium.onnx.json"
  }
}
```

The keys must match the language codes configured in HearMe.

## Health Checks

From the Drupal server/container, verify Piper is reachable:

```bash
curl -f http://piper-service:5000/health
```

Verify synthesis:

```bash
curl -X POST http://piper-service:5000/tts \
  -H "Content-Type: application/json" \
  -d '{"text":"How to be a cat","lang":"en"}' \
  --output hearme-test.wav
```

## Security

Do not expose an unauthenticated Piper service directly to the public internet. Keep it on a private network or behind an authenticating reverse proxy.

Even when Piper is private, the Drupal `/hear-me/tts` endpoint can expose resource consumption to site users. Use HearMe permissions, rate limits, quotas, and cache retention settings appropriately.

## Operations Notes

- Piper can be CPU-intensive. Size rate limits and queue throughput for the host.
- Runtime caching reduces repeated synthesis calls for identical text/language/provider/config combinations.
- Changing provider configuration changes HearMe's cache key, so stale audio is not reused after settings changes.
- If voice files change behind the same provider configuration, clear the generated runtime cache manually from the settings form.
