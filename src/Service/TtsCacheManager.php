<?php

namespace Drupal\hear_me\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\hear_me\TtsAudioResult;
use Drupal\hear_me\TtsSynthesisResult;

/**
 * Manages file-backed runtime cache entries for generated TTS audio.
 */
class TtsCacheManager {

  public const RUNTIME_PRIVATE_URI_BASE = 'private://hear_me/tts/';

  public const RUNTIME_PUBLIC_URI_BASE = TtsFileHelperInterface::TTS_URI_BASE;

  private ?bool $metadataAvailable = NULL;

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('hear_me');
  }

  protected \Psr\Log\LoggerInterface $logger;

  public function normalizeSource(string $source): string {
    $source = strtolower(trim($source));
    return in_array($source, ['inline', 'page', 'selection', 'adhoc', 'entity'], TRUE) ? $source : 'adhoc';
  }

  public function isRuntimeCacheEnabled(string $source): bool {
    $source = $this->normalizeSource($source);
    if ($source === 'entity') {
      return FALSE;
    }

    if (!$this->configFactory->get('hear_me.settings')->get('cache_enabled')) {
      return FALSE;
    }

    if ($this->getTtlForSource($source) <= 0) {
      return FALSE;
    }

    if (!$this->isRuntimeCacheStorageAvailable()) {
      return FALSE;
    }

    return TRUE;
  }

  public function getTtlForSource(string $source): int {
    $source = $this->normalizeSource($source);
    $key = match ($source) {
      'inline' => 'cache_inline_ttl',
      'page' => 'cache_page_ttl',
      'selection' => 'cache_selection_ttl',
      'entity' => 'cache_entity_ttl',
      default => 'cache_ad_hoc_ttl',
    };

    $defaults = [
      'cache_inline_ttl' => 2592000,
      'cache_page_ttl' => 86400,
      'cache_selection_ttl' => 3600,
      'cache_ad_hoc_ttl' => 0,
      'cache_entity_ttl' => 0,
    ];

    $value = $this->configFactory->get('hear_me.settings')->get($key);
    if (!is_numeric($value)) {
      $value = $defaults[$key];
    }

    return max(0, (int) $value);
  }

  public function buildProviderConfigHash(array $providerConfig): string {
    ksort($providerConfig);
    return hash('sha256', serialize($providerConfig));
  }

  public function buildCacheId(
    string $text,
    string $lang,
    string $providerKey,
    string $extension,
    string $source,
    string $providerConfigHash,
  ): string {
    $safeExtension = $this->sanitizeExtension($extension);
    $source = $this->normalizeSource($source);
    $textHash = hash('sha256', $text);

    return hash('sha256', implode("\0", [
      $source,
      $source === 'entity' ? 'public' : $this->getRuntimeCacheStorageKey(),
      $textHash,
      strtolower($lang),
      $providerKey,
      $safeExtension,
      $providerConfigHash,
    ]));
  }

  public function buildUri(string $cid, string $extension, string $source = 'adhoc'): string {
    $source = $this->normalizeSource($source);
    $baseUri = $source === 'entity'
      ? TtsFileHelperInterface::TTS_URI_BASE
      : $this->getRuntimeCacheBaseUri();

    return $baseUri . $cid . '.' . $this->sanitizeExtension($extension);
  }

  public function getRuntimeCacheScheme(): string {
    $scheme = (string) $this->configFactory->get('hear_me.settings')->get('runtime_cache_scheme');
    return in_array($scheme, ['private', 'public'], TRUE) ? $scheme : 'private';
  }

  public function getRuntimeCacheBaseUri(): string {
    return $this->getRuntimeCacheScheme() === 'public'
      ? self::RUNTIME_PUBLIC_URI_BASE
      : self::RUNTIME_PRIVATE_URI_BASE;
  }

  public function isRuntimeCacheStorageAvailable(): bool {
    if (!$this->streamWrapperManager->isValidScheme($this->getRuntimeCacheScheme())) {
      return FALSE;
    }

    $directory = $this->getRuntimeCacheBaseUri();
    return $this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
  }

  public function getCachedAudio(string $cid, int $ttl): ?TtsAudioResult {
    if (!$this->metadataTableAvailable()) {
      return NULL;
    }

    $row = $this->database->select('hear_me_audio_cache', 'c')
      ->fields('c')
      ->condition('cid', $cid)
      ->execute()
      ->fetchObject();

    if (!$row) {
      return NULL;
    }

    $now = $this->time->getRequestTime();
    if ((int) $row->expires > 0 && (int) $row->expires <= $now) {
      $this->deleteRows([$row]);
      return NULL;
    }

    $realpath = $this->fileSystem->realpath($row->uri);
    if (!$realpath || !file_exists($realpath)) {
      $this->deleteRows([$row]);
      return NULL;
    }

    $bytes = file_get_contents($realpath);
    if ($bytes === FALSE) {
      $this->logger->warning('HearMe: cached audio file @uri could not be read.', ['@uri' => $row->uri]);
      return NULL;
    }

    $expires = (int) $row->expires;
    if ($ttl > 0) {
      $expires = max($expires, $now + $ttl);
    }

    $this->database->update('hear_me_audio_cache')
      ->fields([
        'last_accessed' => $now,
        'expires' => $expires,
      ])
      ->expression('access_count', 'access_count + 1')
      ->condition('cid', $cid)
      ->execute();

    return new TtsAudioResult(
      $bytes,
      (string) $row->mime_type,
      (string) $row->extension,
      (string) $row->uri,
      $row->fid === NULL ? NULL : (int) $row->fid,
    );
  }

  public function saveAudio(
    string $cid,
    string $uri,
    string $source,
    string $providerKey,
    string $lang,
    string $text,
    string $providerConfigHash,
    TtsSynthesisResult $result,
    int $ttl,
  ): TtsAudioResult {
    if (!$this->metadataTableAvailable()) {
      $this->logger->warning('HearMe: audio cache metadata table is missing; generated audio was not persisted. Run database updates.');
      return new TtsAudioResult($result->bytes, $result->mimeType, $result->extension);
    }

    $source = $this->normalizeSource($source);
    $directory = $this->getDirectoryFromUri($uri);
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      $this->logger->error('HearMe: failed to prepare audio cache directory @directory.', ['@directory' => $directory]);
      return new TtsAudioResult($result->bytes, $result->mimeType, $result->extension);
    }

    $savedUri = $this->fileSystem->saveData($result->bytes, $uri, FileExists::Replace);
    if (!$savedUri) {
      $this->logger->error('HearMe: failed to save synthesized audio data to @uri.', ['@uri' => $uri]);
      return new TtsAudioResult($result->bytes, $result->mimeType, $result->extension);
    }

    $fid = $this->ensureFileEntity($savedUri);
    $now = $this->time->getRequestTime();
    $expires = $ttl > 0 ? $now + $ttl : 0;
    $filesize = strlen($result->bytes);

    $this->database->merge('hear_me_audio_cache')
      ->key('cid', $cid)
      ->fields([
        'uri' => $savedUri,
        'fid' => $fid,
        'source' => $source,
        'provider' => $providerKey,
        'langcode' => strtolower($lang),
        'text_hash' => hash('sha256', $text),
        'config_hash' => $providerConfigHash,
        'extension' => $this->sanitizeExtension($result->extension),
        'mime_type' => $result->mimeType,
        'filesize' => $filesize,
        'created' => $now,
        'changed' => $now,
        'last_accessed' => $now,
        'expires' => $expires,
        'access_count' => 0,
      ])
      ->execute();

    if ($source !== 'entity') {
      $this->cleanup();
    }

    return new TtsAudioResult($result->bytes, $result->mimeType, $result->extension, $savedUri, $fid);
  }

  public function cleanup(): int {
    if (!$this->metadataTableAvailable()) {
      return 0;
    }

    $deleted = 0;
    $now = $this->time->getRequestTime();

    $expired = $this->database->select('hear_me_audio_cache', 'c')
      ->fields('c')
      ->condition('source', 'entity', '<>')
      ->condition('expires', 0, '>')
      ->condition('expires', $now, '<=')
      ->execute()
      ->fetchAll();
    $deleted += $this->deleteRows($expired);

    $deleted += $this->enforceMaxFiles();
    $deleted += $this->enforceMaxTotalSize();

    return $deleted;
  }

  public function clearRuntimeCache(): int {
    if (!$this->metadataTableAvailable()) {
      return 0;
    }

    $rows = $this->database->select('hear_me_audio_cache', 'c')
      ->fields('c')
      ->condition('source', 'entity', '<>')
      ->execute()
      ->fetchAll();

    return $this->deleteRows($rows);
  }

  public function getStats(): array {
    if (!$this->metadataTableAvailable()) {
      return ['count' => 0, 'bytes' => 0];
    }

    $count = (int) $this->database->select('hear_me_audio_cache', 'c')
      ->condition('source', 'entity', '<>')
      ->countQuery()
      ->execute()
      ->fetchField();

    $query = $this->database->select('hear_me_audio_cache', 'c')
      ->condition('source', 'entity', '<>');
    $query->addExpression('COALESCE(SUM(filesize), 0)', 'total_size');
    $bytes = (int) $query->execute()->fetchField();

    return ['count' => $count, 'bytes' => $bytes];
  }

  protected function metadataTableAvailable(): bool {
    if ($this->metadataAvailable === NULL) {
      $this->metadataAvailable = $this->database->schema()->tableExists('hear_me_audio_cache');
    }
    return $this->metadataAvailable;
  }

  protected function enforceMaxFiles(): int {
    $maxFiles = $this->getConfigInt('cache_max_files', 5000);
    if ($maxFiles <= 0) {
      return 0;
    }

    $count = (int) $this->database->select('hear_me_audio_cache', 'c')
      ->condition('source', 'entity', '<>')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count <= $maxFiles) {
      return 0;
    }

    $rows = $this->database->select('hear_me_audio_cache', 'c')
      ->fields('c')
      ->condition('source', 'entity', '<>')
      ->orderBy('last_accessed', 'ASC')
      ->range(0, $count - $maxFiles)
      ->execute()
      ->fetchAll();

    return $this->deleteRows($rows);
  }

  protected function enforceMaxTotalSize(): int {
    $maxMb = $this->getConfigInt('cache_max_total_mb', 512);
    if ($maxMb <= 0) {
      return 0;
    }

    $maxBytes = $maxMb * 1024 * 1024;
    $stats = $this->getStats();
    if ($stats['bytes'] <= $maxBytes) {
      return 0;
    }

    $bytesToFree = $stats['bytes'] - $maxBytes;
    $candidateRows = $this->database->select('hear_me_audio_cache', 'c')
      ->fields('c')
      ->condition('source', 'entity', '<>')
      ->orderBy('last_accessed', 'ASC')
      ->execute()
      ->fetchAll();

    $rowsToDelete = [];
    $selectedBytes = 0;
    foreach ($candidateRows as $row) {
      $rowsToDelete[] = $row;
      $selectedBytes += (int) $row->filesize;
      if ($selectedBytes >= $bytesToFree) {
        break;
      }
    }

    return $this->deleteRows($rowsToDelete);
  }

  protected function deleteRows(array $rows): int {
    if (empty($rows)) {
      return 0;
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');
    $cids = [];

    foreach ($rows as $row) {
      if (($row->source ?? NULL) === 'entity') {
        continue;
      }

      $cids[] = $row->cid;
      $uri = (string) $row->uri;
      if (!$this->isManagedCacheUri($uri)) {
        continue;
      }

      try {
        $file = NULL;
        if (!empty($row->fid)) {
          $file = $fileStorage->load((int) $row->fid);
        }
        if (!$file) {
          $files = $fileStorage->loadByProperties(['uri' => $uri]);
          $file = $files ? reset($files) : NULL;
        }

        if ($file) {
          $file->delete();
        }
        $realpath = $this->fileSystem->realpath($uri);
        if (!$file && $realpath && is_file($realpath)) {
          $this->fileSystem->delete($uri);
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('HearMe: failed to delete cached audio @uri: @message', [
          '@uri' => $uri,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if ($cids) {
      $this->database->delete('hear_me_audio_cache')
        ->condition('cid', $cids, 'IN')
        ->execute();
    }

    return count($cids);
  }

  protected function ensureFileEntity(string $uri): ?int {
    try {
      $fileStorage = $this->entityTypeManager->getStorage('file');
      $files = $fileStorage->loadByProperties(['uri' => $uri]);
      if ($files) {
        return (int) reset($files)->id();
      }

      $file = $fileStorage->create([
        'uri' => $uri,
        'status' => 1,
      ]);
      $file->save();
      return (int) $file->id();
    }
    catch (\Throwable $e) {
      $this->logger->warning('HearMe: failed to create File entity for @uri: @message', [
        '@uri' => $uri,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  protected function getConfigInt(string $key, int $default): int {
    $value = $this->configFactory->get('hear_me.settings')->get($key);
    return is_numeric($value) ? max(0, (int) $value) : $default;
  }

  protected function sanitizeExtension(string $extension): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';
  }

  protected function getRuntimeCacheStorageKey(): string {
    $scheme = $this->getRuntimeCacheScheme();
    if ($scheme === 'private' && !$this->isRuntimeCacheStorageAvailable()) {
      return 'private-unavailable';
    }

    return $scheme;
  }

  protected function getDirectoryFromUri(string $uri): string {
    $lastSlash = strrpos($uri, '/');
    return $lastSlash === FALSE ? $uri : substr($uri, 0, $lastSlash + 1);
  }

  protected function isManagedCacheUri(string $uri): bool {
    return str_starts_with($uri, self::RUNTIME_PUBLIC_URI_BASE)
      || str_starts_with($uri, self::RUNTIME_PRIVATE_URI_BASE);
  }

}
