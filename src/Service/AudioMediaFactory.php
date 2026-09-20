<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Creates or reuses Media entities for provenance-backed audio Files.
 *
 * @internal
 */
final class AudioMediaFactory {

  private EntityStorageInterface $fileStorage;

  private EntityStorageInterface $mediaStorage;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {
    $this->fileStorage = $entityTypeManager->getStorage('file');
    $this->mediaStorage = $entityTypeManager->getStorage('media');
  }

  /**
   * Creates or reuses Media for an exact provenance-backed File entity.
   */
  public function createFromFile(int $fileId, string $uri, string $lang, string $text): MediaInterface {
    $file = $fileId > 0 ? $this->fileStorage->load($fileId) : NULL;
    if (!$file instanceof FileInterface || $file->getFileUri() !== $uri) {
      throw new \UnexpectedValueException('Persistent audio provenance did not resolve to the expected File entity.');
    }

    $existingMedia = $this->mediaStorage->loadByProperties([
      'bundle' => 'hear_me_audio',
      'field_hear_me_audio_file' => $file->id(),
    ]);
    if ($existingMedia) {
      $media = reset($existingMedia);
      if (!$media instanceof MediaInterface) {
        throw new \UnexpectedValueException('The Media entity storage returned an invalid entity.');
      }
      return $media;
    }

    $media = $this->mediaStorage->create([
      'bundle' => 'hear_me_audio',
      'langcode' => $this->resolveMediaLangcode($lang),
      'name' => 'TTS-' . $lang . '-' . md5($text),
      'field_hear_me_audio_file' => [
        'target_id' => $file->id(),
      ],
    ]);
    if (!$media instanceof MediaInterface) {
      throw new \UnexpectedValueException('The Media entity storage did not create a Media entity.');
    }
    $media->save();

    return $media;
  }

  /**
   * Resolves synthesis language to safe Drupal entity language metadata.
   */
  private function resolveMediaLangcode(string $lang): string {
    $langcode = strtolower(str_replace('_', '-', trim($lang)));
    $language = $this->languageManager->getLanguage($langcode);
    return $language?->getId() ?? LanguageInterface::LANGCODE_NOT_SPECIFIED;
  }

}
