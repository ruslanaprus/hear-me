<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Prevents uninstall from deleting persistent HearMe audio content.
 *
 * @internal
 */
final class HearMeUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    if ($module !== 'hear_me') {
      return [];
    }

    $mediaIds = $this->entityTypeManager->getStorage('media')->getQuery()
      ->condition('bundle', 'hear_me_audio')
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if ($mediaIds) {
      return [
        $this->t('HearMe Audio media exists. Remove or migrate this media before uninstalling HearMe; uninstall never deletes persistent audio content automatically'),
      ];
    }

    $fieldConfigs = $this->entityTypeManager->getStorage('field_config')->loadByProperties([
      'entity_type' => 'media',
      'field_name' => 'field_hear_me_audio_file',
    ]);
    foreach ($fieldConfigs as $fieldConfig) {
      if ($fieldConfig->getTargetBundle() !== 'hear_me_audio') {
        return [
          $this->t('The HearMe audio file field storage is reused by other media types. Remove or migrate those fields before uninstalling HearMe.'),
        ];
      }
    }

    return [];
  }

}
