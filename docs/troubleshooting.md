# Troubleshooting

Start with **Setup status** at **Administration > Configuration > Media > HearMe TTS**. It surfaces the most common install and runtime blockers, including missing provider services, failed provider connection tests, queue worker discovery, media type removal, missing queued audio fields, file storage problems, stale cron, and anonymous access risk.

## The HearMe Block Does Not Appear In Block Layout

Clear Drupal caches after installing or moving module plugin classes.

Without Drush, use **Administration > Configuration > Development > Performance > Clear all caches**.

The block plugin should be discoverable as **HearMe TTS Block** in the **Accessibility** category.

## The Queue Worker Is Not Running

The queue worker plugin ID is `hear_me_tts` and the class must be under:

```text
src/Plugin/QueueWorker/HearMeQueueWorker.php
```

The namespace must be:

```php
namespace Drupal\hear_me\Plugin\QueueWorker;
```

After clearing caches, the queue worker manager should include `hear_me_tts`.

If cron runs but no audio is generated, check:

- **Queue TTS pre-generation for content types** includes the node's content type.
- The configured **TTS Audio Field** exists on that node bundle. Use **Audio field setup** on the HearMe settings form to create it automatically.
- The active provider can synthesize the node language.
- Drupal cron is actually running.
- If the node was edited repeatedly, older stale queue jobs may be skipped. A later job with the current content hash should attach the replacement audio.
- Watchdog logs for provider failures.

## Inline Buttons Do Not Render

Check the text format used by the content:

- **TTS Playback Button** filter is enabled.
- `<tts>` is allowed if **Limit allowed HTML tags and correct faulty HTML** is enabled.
- The saved body still contains `<tts>...</tts>` before filtering.

Clear render caches after changing text format settings.

## Playback Returns 403

The `/hear-me/tts` endpoint requires:

- The **Use TTS playback** permission.
- A valid `X-CSRF-Token` request header.

HearMe's JavaScript fetches the token automatically. If calling the endpoint manually, first request `/session/token` and send the returned value as `X-CSRF-Token`.

## Settings Page Returns 403

The settings page requires **Administer HearMe** (`administer hear me`). It does not use the broad **Administer site configuration** permission.

Grant **Administer HearMe** only to trusted site builders because it controls provider endpoints, caching, rate limits, queue setup, and setup actions.

## Playback Returns 405

The TTS route only accepts `POST`. A browser visit to `/hear-me/tts` by `GET` is expected to fail with Method Not Allowed.

## Playback Returns 429 Or Quota Errors

The Flood API rate limits or daily/monthly quotas have been reached.

Review settings at **Administration > Configuration > Media > HearMe TTS**:

- Rate-limit window seconds.
- Requests per user.
- Requests per IP.
- Requests per role set.
- Daily user quota.
- Monthly user quota.

Anonymous traffic is counted by IP. Authenticated traffic is counted by user ID.

## Playback Shows An Error But No Audio

Check the browser console and network tab first. Then check Drupal logs.

Common causes:

- Piper endpoint URL is not reachable from the Drupal server/container.
- Piper does not have a voice for the requested language.
- The response is not an audio response.
- Private files are not configured and runtime caching is expected to persist files.
- Provider timeout or backend process failure.

## Piper Works From The Host But Not From Drupal

In Docker, `localhost` means the current container. If Drupal and Piper are separate services, Drupal usually needs the Compose service name:

```text
http://piper-service:5000/tts
```

Verify from inside the Drupal container or server, not from your host browser.

## Runtime Cache Does Not Persist

Runtime cache persistence requires:

- **Enable file-based caching** is checked.
- The TTL for the source type is greater than `0`.
- The selected stream wrapper is available.

By default, HearMe uses private files. Configure `file_private_path` or switch **Runtime cache file storage** to public if generated audio is safe to expose by URL.

## Generated Files Are Public

Runtime files are private by default, but can be public if **Runtime cache file storage** is set to public.

Queue-generated entity audio uses `public://tts/` because it is intended to be attached as media. Review site access requirements before exposing generated media.

## Uninstall Is Blocked

Drupal prevents uninstall while plugin/config dependencies are still active.

Common blockers:

- The **TTS Playback Button** filter is enabled on a text format.
- A block placement config still references the HearMe block.

Disable the filter or remove dependent config through the UI, then retry uninstall.

## Clean Up Generated Audio Manually

Use **Clear generated runtime audio cache** on the HearMe settings form to clear tracked runtime playback files.

On uninstall, HearMe removes tracked generated audio under:

- `private://hear_me/tts/`
- `public://tts/`

It skips managed File entities outside those module-owned directories and files still used elsewhere.

Node fields created through **Audio field setup** are not deleted automatically. Remove those fields manually if you no longer need them after uninstall.
