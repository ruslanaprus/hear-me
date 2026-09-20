# Troubleshooting

Start with **Setup status** at **Administration > Configuration > Media > HearMe TTS**. It surfaces the most common install and runtime blockers, including missing provider plugins, failed provider connection tests, queue worker discovery, media type removal, missing queued audio fields, file storage problems, stale cron, and anonymous access risk.

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
- **Queue source fields** has at least one selected source for that content type. Existing installs default to title plus Body until the form is saved.
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

- Piper-compatible endpoint URL is not reachable from the Drupal server.
- Piper does not have a voice for the requested language.
- The response is not an audio response.
- Private files are not configured and runtime caching is expected to persist files.
- Provider timeout or backend process failure.

## Piper-Compatible Service Works From A Browser But Not From Drupal

The endpoint must be reachable from the Drupal server process, not only from your workstation or browser. Verify connectivity from the same server environment that runs PHP, then configure HearMe with that reachable URL.

## Runtime Cache Does Not Persist

Runtime cache persistence requires:

- **Enable file-based caching** is checked.
- The TTL for the source type is greater than `0`.
- The selected stream wrapper is available.

By default, `/hear-me/tts` runtime playback caching uses private files. Configure `file_private_path` or switch **Runtime cache file storage** to public only if click-generated runtime audio is safe to expose by URL. This setting does not make queue-generated entity audio private.

## Existing Content Was Not Queued

The backfill action only scans content types selected under **Queue TTS pre-generation for content types**. It skips nodes when the configured TTS audio field is missing or incompatible, the field already has audio and missing-only mode is used, the configured title/source fields produce no safe source text, or the resolved language is not supported by the active provider. Formatted fields can produce no source when their stored format is empty, missing, or disabled, filters remove all content, or filter processing fails; HearMe omits them rather than using raw storage.

If a backfill reports fewer queued jobs than scanned nodes, check the skipped counts and publication warning. Re-running backfill before cron processes an already published identical job reports a duplicate. A queue-backend failure is reported separately; HearMe retains its pending ownership marker and cron retries publication with the same token.

Use **Create HearMe audio field** first, review **Queue source fields**, verify the affected text formats are enabled and processing correctly for anonymous visitors, then run **Queue existing content** again. Use **Requeue content that already has audio** after relevant filter changes or when replacing audio created by an older pre-release build that read raw formatted storage. If you use an existing audio field, confirm that it is an entity reference to media and allows the `hear_me_audio` bundle. The optional `drush hear-me:queue-existing --requeue-existing` command targets Drush 13.7 or later, but minimum-version discovery and execution remain a pre-release verification blocker.

When a queued job succeeds, HearMe saves the node to attach the generated Media entity. If you see changed timestamps, search indexing, cache invalidation, or integration hooks firing after queue processing, that is expected. HearMe does not intentionally create a revision or change `moderation_state`, but moderation/workflow modules can enforce their own revision behaviour.

## Generated Files Are Public

Runtime files are private by default, but can be public if **Runtime cache file storage** is set to public.

Queue-generated entity audio uses `public://tts/` because it is intended to be attached as Media/File entities. Review site access requirements before exposing generated media.

HearMe queues only published nodes that anonymous visitors can view and includes only source fields viewable by anonymous visitors. The current pre-release path can detach stale/ineligible provenance-backed audio but checks only current entity references before deletion. D10.5-D10.7 block release until transient failures cannot trigger retraction, retained revisions are protected, and grant-only access changes can be reconciled. Manual or unknown audio is preserved. If a custom access module reports access incorrectly, disable queue generation until its node and field access integration is corrected.

## Uninstall Is Blocked

Drupal prevents uninstall while plugin/config dependencies are still active.

Common blockers:

- The **TTS Playback Button** filter is enabled on a text format.
- A block placement config still references the HearMe block.
- HearMe Audio media still exists and must be removed or migrated first.
- Another Media type reuses the module-owned `field_hear_me_audio_file` storage.

Disable the filter or remove dependent config through the UI, then retry uninstall.

## Clean Up Generated Audio Manually

Use **Clear generated runtime audio cache** on the HearMe settings form to clear tracked runtime playback files.

Uninstall clears tracked runtime playback cache files, pending queue jobs, and module-owned key-value markers. It does not delete persistent Media or File entities and is blocked until HearMe Audio media has been removed or migrated. The separate normal content lifecycle cleanup applies only to provenance-backed generated audio that no entity references any longer.

Node fields created through **Audio field setup** are site-owned. Before uninstall, migrate or explicitly delete every such field that still targets the HearMe Audio Media bundle. D10.9 must make the uninstall validator name those fields and block uninstall rather than allowing Drupal dependency removal to delete or broaden them implicitly.
