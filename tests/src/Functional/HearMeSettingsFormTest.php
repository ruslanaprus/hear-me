<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Functional;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\hear_me\Form\HearMeSettingsForm;
use Drupal\hear_me\Service\HearMeExistingContentQueue;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests HearMe settings form behavior.
 */
#[Group('hear_me')]
#[RunTestsInSeparateProcesses]
class HearMeSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'hear_me', 'hear_me_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests saving settings and validating protected Piper endpoint URLs.
   */
  public function testSettingsFormSavesAndValidatesPiperEndpoint(): void {
    $admin = $this->drupalCreateUser(['administer hear me']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/media/hear-me');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('HearMe TTS Settings');

    $this->submitForm([
      'provider' => 'piper',
      'runtime_cache_scheme' => 'public',
      'max_text_length' => 4000,
      'provider_settings[endpoint]' => 'https://tts.example.com/tts',
      'provider_settings[supported_langs]' => 'en, uk',
      'provider_settings[default_lang]' => 'en',
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSame('public', $this->config('hear_me.settings')->get('runtime_cache_scheme'));
    $this->assertSame(4000, $this->config('hear_me.settings')->get('max_text_length'));
    $this->assertSame('https://tts.example.com/tts', $this->config('hear_me.provider.piper')->get('endpoint'));
    $this->assertSame(['en', 'uk'], $this->config('hear_me.provider.piper')->get('supported_langs'));

    $this->submitForm([
      'provider_settings[endpoint]' => 'http://127.0.0.1:5000/tts',
      'provider_settings[allow_private_endpoint_urls]' => FALSE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('Loopback, private, link-local, multicast, and reserved IP endpoint URLs are blocked by default.');
    $this->assertSame('https://tts.example.com/tts', $this->config('hear_me.provider.piper')->get('endpoint'));
    $this->assertFalse($this->config('hear_me.provider.piper')->get('allow_private_endpoint_urls'));

    $this->submitForm([
      'provider_settings[endpoint]' => 'http://127.0.0.1:5000/tts',
      'provider_settings[allow_private_endpoint_urls]' => TRUE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');
    $this->assertSame('http://127.0.0.1:5000/tts', $this->config('hear_me.provider.piper')->get('endpoint'));
    $this->assertTrue($this->config('hear_me.provider.piper')->get('allow_private_endpoint_urls'));

    $this->submitForm([
      'provider_settings[endpoint]' => 'http://192.168.1.20:5000/tts',
      'provider_settings[allow_private_endpoint_urls]' => FALSE,
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('Loopback, private, link-local, multicast, and reserved IP endpoint URLs are blocked by default.');
    $this->assertSame('http://127.0.0.1:5000/tts', $this->config('hear_me.provider.piper')->get('endpoint'));
    $this->assertTrue($this->config('hear_me.provider.piper')->get('allow_private_endpoint_urls'));
  }

  /**
   * Tests custom audio field provisioning across multiple content types.
   */
  public function testAudioFieldProvisioningAndDisplays(): void {
    $admin = $this->drupalCreateUser(['administer hear me']);
    $this->drupalLogin($admin);
    foreach (['article' => 'Article', 'page' => 'Page', 'news' => 'News'] as $type => $name) {
      $this->drupalCreateContentType(['type' => $type, 'name' => $name]);
    }
    $this->assertNull(FieldStorageConfig::loadByName('node', 'field_custom_audio'));

    $this->drupalGet('/admin/config/media/hear-me');
    $this->submitForm([
      'tts_audio_field' => 'field_custom_audio',
      'audio_field_setup[bundles][article]' => TRUE,
      'audio_field_setup[bundles][page]' => TRUE,
      'audio_field_setup[bundles][news]' => TRUE,
    ], 'Create HearMe audio field');

    $this->assertSession()->pageTextContains('Created the HearMe audio field on 3 content types.');
    $this->assertSame('field_custom_audio', $this->config('hear_me.settings')->get('tts_audio_field'));

    $storage = FieldStorageConfig::loadByName('node', 'field_custom_audio');
    $this->assertInstanceOf(FieldStorageConfig::class, $storage);
    $this->assertSame('entity_reference', $storage->getType());
    $this->assertSame('media', $storage->getSetting('target_type'));
    $this->assertSame(1, $storage->getCardinality());
    $this->assertTrue($storage->isTranslatable());

    foreach (['article', 'page', 'news'] as $bundle) {
      $field = FieldConfig::loadByName('node', $bundle, 'field_custom_audio');
      $this->assertInstanceOf(FieldConfig::class, $field);
      $this->assertSame(['hear_me_audio' => 'hear_me_audio'], $field->getSetting('handler_settings')['target_bundles']);
      $this->assertTrue($field->isTranslatable());

      $formDisplay = EntityFormDisplay::load("node.$bundle.default");
      $this->assertInstanceOf(EntityFormDisplay::class, $formDisplay);
      $this->assertNull($formDisplay->getComponent('field_custom_audio'));

      $viewDisplay = EntityViewDisplay::load("node.$bundle.default");
      $this->assertInstanceOf(EntityViewDisplay::class, $viewDisplay);
      $component = $viewDisplay->getComponent('field_custom_audio');
      $this->assertSame('entity_reference_entity_view', $component['type']);
      $this->assertSame('above', $component['label']);
      $this->assertSame(['view_mode' => 'default', 'link' => FALSE], $component['settings']);
      $this->assertSame(90, $component['weight']);
    }
  }

  /**
   * Tests compatible existing fields are skipped without being changed.
   */
  public function testCompatibleExistingAudioFieldIsSkippedWithoutMutation(): void {
    $admin = $this->drupalCreateUser(['administer hear me']);
    $this->drupalLogin($admin);
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->createAudioReferenceField('article', 'field_existing_audio');

    $field = FieldConfig::loadByName('node', 'article', 'field_existing_audio');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $field->setLabel('Sentinel audio label');
    $field->setDescription('Sentinel audio description.');
    $field->setTranslatable(FALSE);
    $field->save();

    $formDisplay = EntityFormDisplay::load('node.article.default')
      ?: EntityFormDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => 'article',
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $formDisplay->setComponent('field_existing_audio', [
      'type' => 'entity_reference_autocomplete',
      'settings' => [
        'match_operator' => 'STARTS_WITH',
        'match_limit' => 7,
        'size' => 33,
        'placeholder' => 'Sentinel placeholder',
      ],
      'weight' => 17,
    ])->save();
    $formComponent = $formDisplay->getComponent('field_existing_audio');

    $viewDisplay = EntityViewDisplay::load('node.article.default')
      ?: EntityViewDisplay::create([
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
    $viewComponent = $viewDisplay->getComponent('field_existing_audio');

    $this->drupalGet('/admin/config/media/hear-me');
    $this->submitForm([
      'tts_audio_field' => 'field_existing_audio',
      'audio_field_setup[bundles][article]' => TRUE,
    ], 'Create HearMe audio field');

    $this->assertSession()->pageTextContains('Skipped 1 content type because the field already exists or the content type no longer exists.');
    $field = FieldConfig::loadByName('node', 'article', 'field_existing_audio');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $this->assertSame('Sentinel audio label', $field->label());
    $this->assertSame('Sentinel audio description.', $field->getDescription());
    $this->assertFalse($field->isTranslatable());
    $this->assertSame(
      $formComponent,
      EntityFormDisplay::load('node.article.default')->getComponent('field_existing_audio'),
    );
    $this->assertSame(
      $viewComponent,
      EntityViewDisplay::load('node.article.default')->getComponent('field_existing_audio'),
    );
  }

  /**
   * Tests incompatible existing field storage blocks provisioning.
   */
  public function testIncompatibleAudioFieldStorageIsRejected(): void {
    $admin = $this->drupalCreateUser(['administer hear me']);
    $this->drupalLogin($admin);
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    FieldStorageConfig::create([
      'field_name' => 'field_incompatible_audio',
      'entity_type' => 'node',
      'type' => 'integer',
    ])->save();

    $this->drupalGet('/admin/config/media/hear-me');
    $this->submitForm([
      'tts_audio_field' => 'field_incompatible_audio',
      'audio_field_setup[bundles][article]' => TRUE,
    ], 'Create HearMe audio field');

    $this->assertSession()->pageTextContains('The existing field storage is not compatible with HearMe audio: field_incompatible_audio must be an entity reference field.');
    $this->assertNull(FieldConfig::loadByName('node', 'article', 'field_incompatible_audio'));
    $this->assertSame('field_tts_audio', $this->config('hear_me.settings')->get('tts_audio_field'));
  }

  /**
   * Tests the Batch API definition registered after backfill confirmation.
   */
  public function testExistingContentBackfillBatchRegistration(): void {
    $formState = (new FormState())->set('hear_me_backfill_confirmation', [
      'field_name' => 'field_backfill_audio',
      'bundles' => ['article'],
      'source_fields' => ['article' => ['title' => TRUE, 'fields' => []]],
      'published_only' => TRUE,
      'missing_only' => TRUE,
    ]);
    $form = [];

    try {
      HearMeSettingsForm::create($this->container)->queueExistingContentSubmit($form, $formState);

      $batch =& batch_get();
      $batchSet = $batch['sets'][0];
      $this->assertSame([HearMeSettingsForm::class, 'queueExistingContentBatchOperation'], $batchSet['operations'][0][0]);
      $this->assertSame([
        'bundles' => ['article'],
        'published_only' => TRUE,
        'missing_only' => TRUE,
        'batch_size' => HearMeExistingContentQueue::DEFAULT_BATCH_SIZE,
      ], $batchSet['operations'][0][1][0]);
      $this->assertSame([HearMeSettingsForm::class, 'queueExistingContentBatchFinished'], $batchSet['finished']);
    }
    finally {
      $batch =& batch_get();
      $batch = [];
    }
  }

  /**
   * Tests review, cancellation, validation, and Batch backfill behavior.
   */
  public function testExistingContentBackfillWorkflow(): void {
    $admin = $this->drupalCreateUser(['administer hear me']);
    $this->drupalLogin($admin);
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->createAudioReferenceField('article', 'field_backfill_audio');
    $this->createSourceField('article', 'field_intro_source', 'string', 'Intro source');

    Node::create(['type' => 'article', 'title' => 'Published node', 'status' => 1])->save();
    Node::create(['type' => 'article', 'title' => 'Unpublished node', 'status' => 0])->save();
    $queue = $this->container->get('queue')->get('hear_me_tts');

    $this->drupalGet('/admin/config/media/hear-me');
    $this->submitForm($this->backfillEdit(), 'Queue existing content');

    $this->assertSession()->pageTextContains('Review the existing content queue estimate, then confirm or cancel. No audio jobs have been queued yet.');
    $this->assertSession()->pageTextContains('Estimated candidate nodes to scan: 1.');
    $this->assertSession()->pageTextContains('Selected content types: Article (article).');
    $this->assertSession()->pageTextContains('Publication mode: published content only.');
    $this->assertSession()->pageTextContains('Audio field mode: queue only nodes missing audio.');
    $this->assertSession()->buttonExists('Confirm queue existing content');
    $this->assertSame(0, $queue->numberOfItems());

    $this->submitForm([
      'queue_source_fields[article][title]' => FALSE,
      'queue_source_fields[article][fields][field_intro_source:value]' => TRUE,
    ], 'Confirm queue existing content');
    $this->assertSession()->pageTextContains('The existing content queue settings changed after the estimate was generated. Click Queue existing content again to review the updated estimate.');
    $this->assertSame(0, $queue->numberOfItems());

    $this->submitForm([], 'Cancel');
    $this->assertSession()->pageTextContains('Existing content queueing was cancelled. No audio jobs were queued.');
    $this->assertSession()->buttonNotExists('Confirm queue existing content');
    $this->assertSame(0, $queue->numberOfItems());

    $unpublishedEdit = $this->backfillEdit() + [
      'existing_content_queue[include_unpublished]' => TRUE,
    ];
    $this->submitForm($unpublishedEdit, 'Queue existing content');
    $this->assertSession()->pageTextContains('Confirm that generated audio for unpublished content may be publicly accessible before queueing unpublished content.');
    $this->assertSession()->buttonNotExists('Confirm queue existing content');
    $this->assertSame(0, $queue->numberOfItems());

    $unpublishedEdit['existing_content_queue[confirm_unpublished_public_audio]'] = TRUE;
    $this->submitForm($unpublishedEdit, 'Queue existing content');
    $this->assertSession()->pageTextContains('Estimated candidate nodes to scan: 2.');
    $this->assertSession()->pageTextContains('Publication mode: published and unpublished content.');
    $this->assertSame(0, $queue->numberOfItems());

    $this->submitForm([], 'Confirm queue existing content');
    $this->assertSession()->pageTextContains('Existing content queueing finished. Scanned 2 node(s), queued 2 audio job(s).');
    $this->assertSame(2, $queue->numberOfItems());
  }

  /**
   * Tests queue source field options only include supported field types.
   */
  public function testQueueSourceFieldOptionsOnlyOfferSupportedFields(): void {
    $admin = $this->drupalCreateUser(['administer hear me']);
    $this->drupalLogin($admin);
    $this->drupalCreateContentType([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $this->createSourceField('article', 'field_summary_source', 'text_with_summary', 'Summary source');
    $this->createSourceField('article', 'field_intro_source', 'string', 'Intro source');
    $this->createSourceField('article', 'field_rating_source', 'integer', 'Rating source');

    $this->drupalGet('/admin/config/media/hear-me');

    $this->assertSession()->fieldExists('queue_source_fields[article][title]');
    $this->assertSession()->fieldExists('queue_source_fields[article][fields][field_summary_source:value]');
    $this->assertSession()->fieldExists('queue_source_fields[article][fields][field_summary_source:summary]');
    $this->assertSession()->fieldExists('queue_source_fields[article][fields][field_intro_source:value]');
    $this->assertSession()->fieldNotExists('queue_source_fields[article][fields][field_rating_source:value]');
  }

  /**
   * Creates a node field for settings form source option tests.
   */
  protected function createSourceField(string $bundle, string $field_name, string $type, string $label): void {
    $storage = FieldStorageConfig::loadByName('node', $field_name);
    if ($storage) {
      $this->assertSame($type, $storage->getType(), "The $field_name field already exists with an unexpected type.");
    }
    else {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $label,
      ])->save();
    }
  }

  /**
   * Creates a compatible node media reference field.
   */
  protected function createAudioReferenceField(string $bundle, string $fieldName): void {
    if (!FieldStorageConfig::loadByName('node', $fieldName)) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'media'],
        'cardinality' => 1,
      ])->save();
    }

    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => 'HearMe audio',
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => ['hear_me_audio' => 'hear_me_audio'],
        ],
      ],
    ])->save();
  }

  /**
   * Returns the common valid backfill form values.
   */
  protected function backfillEdit(): array {
    return [
      'tts_audio_field' => 'field_backfill_audio',
      'queue_bundles[article]' => TRUE,
      'queue_source_fields[article][title]' => TRUE,
    ];
  }

}
