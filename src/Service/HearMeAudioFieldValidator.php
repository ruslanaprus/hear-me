<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Validates node audio fields used by queue-generated HearMe media.
 */
class HearMeAudioFieldValidator {

  use StringTranslationTrait;

  public const HEAR_ME_AUDIO_BUNDLE = 'hear_me_audio';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Validates existing node field storage, or returns OK when it does not exist.
   */
  public function validateStorage(string $fieldName): array {
    $result = $this->emptyResult();
    $storage = FieldStorageConfig::loadByName('node', $fieldName);
    if (!$storage) {
      return $result;
    }

    $this->validateStorageConfig($storage, $fieldName, $result);
    return $result;
  }

  /**
   * Validates one bundle-level node field configuration.
   */
  public function validateBundle(string $bundle, string $fieldName, bool $warnExistingValues = FALSE): array {
    $result = $this->emptyResult($bundle);
    $bundleLabel = $this->getBundleLabel($bundle);

    if (!$this->entityTypeManager->getStorage('node_type')->load($bundle)) {
      $result['errors'][] = $this->t('Content type @bundle does not exist.', ['@bundle' => $bundle]);
      return $result;
    }

    $field = FieldConfig::loadByName('node', $bundle, $fieldName);
    if (!$field) {
      $result['errors'][] = $this->t('@field is missing on @bundle.', [
        '@field' => $fieldName,
        '@bundle' => $bundleLabel,
      ]);
      return $result;
    }

    if ($field->isDeleted()) {
      $result['errors'][] = $this->t('@field on @bundle is deleted and cannot be used.', [
        '@field' => $fieldName,
        '@bundle' => $bundleLabel,
      ]);
      return $result;
    }

    if (method_exists($field, 'status') && !$field->status()) {
      $result['errors'][] = $this->t('@field on @bundle is disabled and cannot be used.', [
        '@field' => $fieldName,
        '@bundle' => $bundleLabel,
      ]);
      return $result;
    }

    $storage = FieldStorageConfig::loadByName('node', $fieldName);
    if (!$storage) {
      $result['errors'][] = $this->t('@field storage is missing.', ['@field' => $fieldName]);
      return $result;
    }

    $this->validateStorageConfig($storage, $fieldName, $result, $bundleLabel);

    if (!$this->fieldAllowsHearMeAudio($field)) {
      $result['errors'][] = $this->t('@field on @bundle must allow HearMe Audio media.', [
        '@field' => $fieldName,
        '@bundle' => $bundleLabel,
      ]);
    }

    if (!$result['errors']) {
      $result['compatible'] = TRUE;
      if ($warnExistingValues) {
        $existingValueCount = $this->countExistingValues($bundle, $fieldName);
        if ($existingValueCount > 0) {
          $result['warnings'][] = $this->formatPlural(
            $existingValueCount,
            '@field on @bundle already has audio on 1 node. With manual overwrite disabled, existing manual or unknown values will be skipped.',
            '@field on @bundle already has audio on @count nodes. With manual overwrite disabled, existing manual or unknown values will be skipped.',
            [
              '@field' => $fieldName,
              '@bundle' => $bundleLabel,
            ]
          );
        }
      }
    }

    return $result;
  }

  /**
   * Validates multiple bundle-level field configurations.
   */
  public function validateBundles(array $bundles, string $fieldName, bool $warnExistingValues = FALSE): array {
    $summary = [
      'errors' => [],
      'warnings' => [],
      'compatible_bundles' => [],
      'invalid_bundles' => [],
      'bundle_results' => [],
    ];

    foreach ($this->normalizeBundles($bundles) as $bundle) {
      $result = $this->validateBundle($bundle, $fieldName, $warnExistingValues);
      $summary['bundle_results'][$bundle] = $result;
      $summary['errors'] = array_merge($summary['errors'], $result['errors']);
      $summary['warnings'] = array_merge($summary['warnings'], $result['warnings']);

      if ($result['compatible']) {
        $summary['compatible_bundles'][] = $bundle;
      }
      else {
        $summary['invalid_bundles'][] = $bundle;
      }
    }

    return $summary;
  }

  /**
   * Returns only bundles with compatible HearMe audio fields.
   */
  public function getCompatibleBundles(array $bundles, string $fieldName): array {
    return $this->validateBundles($bundles, $fieldName)['compatible_bundles'];
  }

  /**
   * Checks whether one bundle has a compatible HearMe audio field.
   */
  public function isBundleCompatible(string $bundle, string $fieldName): bool {
    return (bool) $this->validateBundle($bundle, $fieldName)['compatible'];
  }

  /**
   * Validates shared field storage properties.
   */
  protected function validateStorageConfig(FieldStorageConfig $storage, string $fieldName, array &$result, ?string $bundleLabel = NULL): void {
    $context = $bundleLabel === NULL
      ? ['@field' => $fieldName]
      : ['@field' => $fieldName, '@bundle' => $bundleLabel];

    if ($storage->isDeleted()) {
      $result['errors'][] = $bundleLabel === NULL
        ? $this->t('@field storage is deleted and cannot be used.', $context)
        : $this->t('@field on @bundle uses deleted field storage and cannot be used.', $context);
      return;
    }

    if ($storage->getType() !== 'entity_reference') {
      $result['errors'][] = $bundleLabel === NULL
        ? $this->t('@field must be an entity reference field.', $context)
        : $this->t('@field on @bundle must be an entity reference field.', $context);
    }

    $targetType = (string) $storage->getSetting('target_type');
    if ($targetType !== 'media') {
      $context['@target'] = $targetType === '' ? $this->t('none') : $targetType;
      $result['errors'][] = $bundleLabel === NULL
        ? $this->t('@field must reference media; it currently targets @target.', $context)
        : $this->t('@field on @bundle must reference media; it currently targets @target.', $context);
    }

    $cardinality = $storage->getCardinality();
    if ($cardinality !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && $cardinality < 1) {
      $result['errors'][] = $bundleLabel === NULL
        ? $this->t('@field must allow at least one value.', $context)
        : $this->t('@field on @bundle must allow at least one value.', $context);
    }

    if ($storage->isLocked()) {
      $result['warnings'][] = $bundleLabel === NULL
        ? $this->t('@field storage is locked. Verify it is safe to use for HearMe generated audio.', $context)
        : $this->t('@field on @bundle uses locked field storage. Verify it is safe to use for HearMe generated audio.', $context);
    }
  }

  /**
   * Checks target bundle restrictions on the bundle field config.
   */
  protected function fieldAllowsHearMeAudio(FieldConfig $field): bool {
    $handlerSettings = $field->getSetting('handler_settings') ?? [];
    $targetBundles = $handlerSettings['target_bundles'] ?? [];
    if (empty($targetBundles)) {
      return TRUE;
    }

    return in_array(self::HEAR_ME_AUDIO_BUNDLE, array_keys($targetBundles), TRUE)
      || in_array(self::HEAR_ME_AUDIO_BUNDLE, array_values($targetBundles), TRUE);
  }

  /**
   * Counts nodes in a bundle with a value in the configured audio field.
   */
  protected function countExistingValues(string $bundle, string $fieldName): int {
    try {
      return (int) $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->exists($fieldName)
        ->count()
        ->execute();
    }
    catch (\Exception) {
      return 0;
    }
  }

  /**
   * Builds a blank validation result.
   */
  protected function emptyResult(?string $bundle = NULL): array {
    return [
      'bundle' => $bundle,
      'compatible' => FALSE,
      'errors' => [],
      'warnings' => [],
    ];
  }

  /**
   * Returns a content type label when available.
   */
  protected function getBundleLabel(string $bundle): string {
    $type = $this->entityTypeManager->getStorage('node_type')->load($bundle);
    return $type ? (string) $type->label() : $bundle;
  }

  /**
   * Normalizes bundle machine names.
   */
  protected function normalizeBundles(array $bundles): array {
    return array_values(array_unique(array_filter(array_map(
      static fn($bundle) => trim((string) $bundle),
      $bundles,
    ))));
  }

}
