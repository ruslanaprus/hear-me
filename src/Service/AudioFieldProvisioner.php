<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provisions node fields and displays for generated audio Media references.
 *
 * @internal
 */
final class AudioFieldProvisioner {

  private EntityStorageInterface $nodeTypeStorage;

  private EntityStorageInterface $fieldStorage;

  private EntityStorageInterface $fieldConfigStorage;

  private EntityStorageInterface $formDisplayStorage;

  private EntityStorageInterface $viewDisplayStorage;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->nodeTypeStorage = $entityTypeManager->getStorage('node_type');
    $this->fieldStorage = $entityTypeManager->getStorage('field_storage_config');
    $this->fieldConfigStorage = $entityTypeManager->getStorage('field_config');
    $this->formDisplayStorage = $entityTypeManager->getStorage('entity_form_display');
    $this->viewDisplayStorage = $entityTypeManager->getStorage('entity_view_display');
  }

  /**
   * Creates the configured audio field on available content bundles.
   *
   * @param string[] $bundles
   *   Node bundle IDs selected for provisioning.
   */
  public function provision(string $fieldName, array $bundles): AudioFieldProvisionResult {
    if (!$this->fieldStorage->load('node.' . $fieldName)) {
      $this->fieldStorage->create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'media',
        ],
        'cardinality' => 1,
        'translatable' => TRUE,
      ])->save();
    }

    $createdBundles = [];
    $skippedBundles = [];
    foreach ($bundles as $bundle) {
      if (!$this->nodeTypeStorage->load($bundle)
        || $this->fieldConfigStorage->load('node.' . $bundle . '.' . $fieldName)) {
        $skippedBundles[] = $bundle;
        continue;
      }

      $this->fieldConfigStorage->create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'HearMe audio',
        'description' => 'Generated text-to-speech audio media attached by HearMe.',
        'required' => FALSE,
        'translatable' => TRUE,
        'settings' => [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => [
              'hear_me_audio' => 'hear_me_audio',
            ],
            'auto_create' => FALSE,
          ],
        ],
      ])->save();

      $this->configureDisplays($bundle, $fieldName);
      $createdBundles[] = $bundle;
    }

    return new AudioFieldProvisionResult($createdBundles, $skippedBundles);
  }

  /**
   * Configures default form and view displays for a provisioned field.
   */
  private function configureDisplays(string $bundle, string $fieldName): void {
    $formDisplayId = 'node.' . $bundle . '.default';
    $formDisplay = $this->formDisplayStorage->load($formDisplayId)
      ?: $this->formDisplayStorage->create([
        'targetEntityType' => 'node',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $formDisplay->removeComponent($fieldName)->save();

    $viewDisplayId = 'node.' . $bundle . '.default';
    $viewDisplay = $this->viewDisplayStorage->load($viewDisplayId)
      ?: $this->viewDisplayStorage->create([
        'targetEntityType' => 'node',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $viewDisplay->setComponent($fieldName, [
      'type' => 'entity_reference_entity_view',
      'label' => 'above',
      'settings' => [
        'view_mode' => 'default',
        'link' => FALSE,
      ],
      'weight' => 90,
    ])->save();
  }

}
