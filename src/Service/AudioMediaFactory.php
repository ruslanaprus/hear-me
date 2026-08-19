<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * Creates or reuses File and Media entities for generated audio.
 *
 * @internal
 */
final class AudioMediaFactory {

  private EntityStorageInterface $fileStorage;

  private EntityStorageInterface $mediaStorage;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->fileStorage = $entityTypeManager->getStorage('file');
    $this->mediaStorage = $entityTypeManager->getStorage('media');
  }

  /**
   * Creates or reuses managed entities for a generated audio URI.
   */
  public function createFromUri(string $uri, string $lang, string $text): MediaInterface {
    $existingFiles = $this->fileStorage->loadByProperties(['uri' => $uri]);

    if ($existingFiles) {
      $file = reset($existingFiles);
    }
    else {
      $file = $this->fileStorage->create([
        'uri' => $uri,
        'status' => 1,
      ]);
      if (!$file instanceof FileInterface) {
        throw new \UnexpectedValueException('The File entity storage did not create a File entity.');
      }
      $file->save();
    }

    if (!$file instanceof FileInterface) {
      throw new \UnexpectedValueException('The File entity storage returned an invalid entity.');
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

}
