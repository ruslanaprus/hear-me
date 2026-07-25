<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
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
  protected static $modules = ['node', 'text', 'hear_me'];

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
    ], 'Save configuration');

    $this->assertSession()->pageTextContains('Loopback, private, link-local, multicast, and reserved IP endpoint URLs are blocked by default.');
    $this->assertSame('https://tts.example.com/tts', $this->config('hear_me.provider.piper')->get('endpoint'));
  }

  /**
   * Tests that the settings form can create compatible node audio fields.
   */
  public function testAudioFieldCanBeCreatedFromSettingsForm(): void {
    $admin = $this->drupalCreateUser(['administer hear me']);
    $this->drupalLogin($admin);
    $this->drupalCreateContentType([
      'type' => 'article',
      'name' => 'Article',
    ]);

    $this->drupalGet('/admin/config/media/hear-me');
    $this->submitForm([
      'tts_audio_field' => 'field_tts_audio',
      'audio_field_setup[bundles][article]' => TRUE,
    ], 'Create HearMe audio field');

    $this->assertSession()->pageTextContains('Created the HearMe audio field on 1 content type.');
    $this->assertInstanceOf(FieldStorageConfig::class, FieldStorageConfig::loadByName('node', 'field_tts_audio'));

    $field = FieldConfig::loadByName('node', 'article', 'field_tts_audio');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $this->assertSame('media', $field->getFieldStorageDefinition()->getSetting('target_type'));
    $this->assertSame(['hear_me_audio' => 'hear_me_audio'], $field->getSetting('handler_settings')['target_bundles']);
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

}
