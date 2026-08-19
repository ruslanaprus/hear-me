<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\hear_me\Service\AudioFieldProvisioner;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests generated audio field and display provisioning.
 */
#[Group('hear_me')]
#[RunTestsInSeparateProcesses]
class AudioFieldProvisionerTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'media',
    'node',
    'hear_me',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['node', 'file', 'image', 'media', 'hear_me']);
  }

  /**
   * Tests creation of storage, a bundle field, and default displays.
   */
  public function testProvisionsFieldAndDisplays(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $provisioner = $this->container->get('hear_me.audio_field_provisioner');
    $this->assertInstanceOf(AudioFieldProvisioner::class, $provisioner);

    $result = $provisioner->provision('field_generated_audio', ['article']);

    $this->assertSame(['article'], $result->createdBundles);
    $this->assertSame([], $result->skippedBundles);
    $storage = FieldStorageConfig::loadByName('node', 'field_generated_audio');
    $this->assertInstanceOf(FieldStorageConfig::class, $storage);
    $this->assertSame('entity_reference', $storage->getType());
    $this->assertSame('media', $storage->getSetting('target_type'));
    $this->assertSame(1, $storage->getCardinality());
    $this->assertTrue($storage->isTranslatable());

    $field = FieldConfig::loadByName('node', 'article', 'field_generated_audio');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $this->assertSame('HearMe audio', $field->label());
    $this->assertSame('Generated text-to-speech audio media attached by HearMe.', $field->getDescription());
    $this->assertFalse($field->isRequired());
    $this->assertTrue($field->isTranslatable());
    $this->assertSame(['hear_me_audio' => 'hear_me_audio'], $field->getSetting('handler_settings')['target_bundles']);

    $formDisplay = EntityFormDisplay::load('node.article.default');
    $this->assertInstanceOf(EntityFormDisplay::class, $formDisplay);
    $this->assertNull($formDisplay->getComponent('field_generated_audio'));
    $viewDisplay = EntityViewDisplay::load('node.article.default');
    $this->assertInstanceOf(EntityViewDisplay::class, $viewDisplay);
    $this->assertSame([
      'type' => 'entity_reference_entity_view',
      'label' => 'above',
      'settings' => [
        'view_mode' => 'default',
        'link' => FALSE,
      ],
      'third_party_settings' => [],
      'weight' => 90,
      'region' => 'content',
    ], $viewDisplay->getComponent('field_generated_audio'));
  }

  /**
   * Tests existing fields and missing bundles are reported and unchanged.
   */
  public function testReportsSkippedBundlesWithoutMutation(): void {
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_existing_audio',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_existing_audio',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Sentinel audio',
    ])->save();
    $formDisplay = EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'article',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $formDisplay->setComponent('field_existing_audio', [
      'type' => 'entity_reference_autocomplete',
      'weight' => 17,
    ])->save();
    $viewDisplay = EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'article',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $viewDisplay->setComponent('field_existing_audio', [
      'type' => 'entity_reference_label',
      'label' => 'hidden',
      'settings' => ['link' => TRUE],
      'weight' => -17,
    ])->save();
    $originalFormComponent = $formDisplay->getComponent('field_existing_audio');
    $originalViewComponent = $viewDisplay->getComponent('field_existing_audio');

    $result = $this->container->get('hear_me.audio_field_provisioner')
      ->provision('field_existing_audio', ['article', 'missing']);

    $this->assertSame([], $result->createdBundles);
    $this->assertSame(['article', 'missing'], $result->skippedBundles);
    $this->assertSame('Sentinel audio', FieldConfig::loadByName('node', 'article', 'field_existing_audio')->label());
    $this->assertSame(
      $originalFormComponent,
      EntityFormDisplay::load('node.article.default')->getComponent('field_existing_audio'),
    );
    $this->assertSame(
      $originalViewComponent,
      EntityViewDisplay::load('node.article.default')->getComponent('field_existing_audio'),
    );
  }

}
