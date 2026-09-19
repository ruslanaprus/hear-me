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

The response `Content-Type` must be `audio/wav` (parameters are allowed), the body must be non-empty, and audio larger than 16 MiB is rejected. Other provider plugins can return other formats by implementing the provider interface and reporting a different MIME type and extension.

## Endpoint Configuration

In HearMe settings, use a URL that is reachable from the Drupal server, for example:

```text
https://tts.example.com/tts
```

Release installs leave the endpoint empty by default. Configure it in the UI or with an environment-specific Drupal config override, for example:

```php
$config['hear_me.provider.piper']['endpoint'] = 'https://tts.example.com/tts';
```

Hostnames that resolve to loopback, private, multicast, or reserved IP addresses require enabling **Allow local/private provider endpoints** and should be used only for trusted services you control. Link-local and known metadata/infrastructure ranges remain blocked.

## Provider Settings

Go to **Administration > Configuration > Media > HearMe TTS**.

Piper-compatible adapter settings:

- **Allow local/private provider endpoints**: default off. Enables trusted loopback, private, or reserved endpoint addresses for local/self-hosted deployments. Link-local and known metadata/infrastructure ranges remain blocked.
- **Piper Endpoint URL**: absolute URL to the `/tts` endpoint.
- **Supported Language Codes**: comma-separated list of voice-registry keys the service can handle, for example, `en, uk_UA`. Case, underscores, and hyphens are preserved when sent to Piper, so each value must exactly match the external service's registry key.
- **Default Language**: fallback voice-registry key used when no language can be resolved from the page context. Case and separator differences are accepted in the form, then saved using the matching Supported Language Codes value.

The endpoint URL must use HTTP or HTTPS, include a host, and must not contain usernames, passwords, or URL fragments. Hostnames must resolve successfully. Immediately before each request, HearMe validates every resolved address and pins the connection to one validated address. Redirects, proxies, and connection reuse are disabled for this request. Loopback, private, multicast, and reserved destinations require **Allow local/private provider endpoints**; link-local and known metadata/infrastructure ranges remain blocked. Network egress rules remain necessary defense in depth for custom HTTP transports and infrastructure ranges not known to the adapter.

If authentication is required, put Piper behind an internal proxy and implement authentication there, or create a custom provider that sends the required headers securely.

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

The keys must exactly match the values configured under **Supported Language Codes** in HearMe. HearMe preserves each configured registry key when sending `lang`; it does not rewrite `uk_UA` to `uk-ua`.

## Health Checks

From the Drupal server, verify Piper is reachable:

```bash
curl -f https://tts.example.com/health
```

Verify synthesis:

```bash
curl -X POST https://tts.example.com/tts \
  -H "Content-Type: application/json" \
  -d '{"text":"How to be a cat","lang":"en"}' \
  --output hearme-test.wav
```

## Security

Do not expose an unauthenticated Piper service directly to the public internet. Keep it on a private network or behind an authenticating reverse proxy.

Drupal sends server-side HTTP requests to the configured endpoint. To reduce SSRF risk, the built-in adapter fails closed on unresolved hostnames, validates every resolved address, pins hostname connections through cURL, disables redirects, proxies, and connection reuse, blocks metadata/link-local infrastructure destinations, blocks other private/local destinations by default, redacts endpoint paths and query data from logs, and accepts only bounded, non-empty `audio/wav` responses. Replacing Drupal's cURL-backed HTTP transport with one that ignores raw cURL options is unsupported for this adapter.

Even when Piper is private, the Drupal `/hear-me/tts` endpoint can expose resource consumption to site users. Use HearMe permissions, rate limits, quotas, and cache retention settings appropriately.

## Operations Notes

- Piper can be CPU-intensive. Size rate limits and queue throughput for the host.
- Runtime caching reduces repeated synthesis calls for identical text/language/provider/config combinations.
- Changing provider configuration changes HearMe's cache key, so stale audio is not reused after settings changes.
- If voice files change behind the same provider configuration, clear the generated runtime cache manually from the settings form.
