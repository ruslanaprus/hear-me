<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Functional;

use Drupal\Core\Form\FormState;
use Drupal\hear_me\Form\HearMeSettingsForm;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests provider-specific settings form rebuilding without JavaScript.
 */
#[Group('hear_me')]
#[RunTestsInSeparateProcesses]
class HearMeProviderSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'text', 'hear_me', 'hear_me_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests provider selection rebuilds the provider settings wrapper.
   */
  public function testProviderSettingsRebuild(): void {
    $formObject = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(HearMeSettingsForm::class);

    $piperState = new FormState();
    $piperForm = $this->container->get('form_builder')->buildForm($formObject, $piperState);
    $this->assertCount(2, $piperForm['provider']['#options']);
    $this->assertSame('Piper (self-hosted)', (string) $piperForm['provider']['#options']['piper']);
    $this->assertSame('Test provider', (string) $piperForm['provider']['#options']['test']);
    $this->assertSame('::ajaxProviderSettings', $piperForm['provider']['#ajax']['callback']);
    $this->assertSame('provider-settings-wrapper', $piperForm['provider']['#ajax']['wrapper']);
    $this->assertTrue($piperForm['provider_settings']['#tree']);
    $this->assertSame('provider-settings-wrapper', $piperForm['provider_settings']['#attributes']['id']);
    $this->assertArrayHasKey('endpoint', $piperForm['provider_settings']);
    $this->assertSame('', $piperForm['provider_settings']['endpoint']['#default_value']);
    $this->assertSame(['provider_settings', 'endpoint'], $piperForm['provider_settings']['endpoint']['#parents']);

    $testState = (new FormState())->setValue('provider', 'test');
    $testForm = $this->container->get('form_builder')->buildForm($formObject, $testState);
    $this->assertTrue($testForm['provider_settings']['#tree']);
    $this->assertSame('provider-settings-wrapper', $testForm['provider_settings']['#attributes']['id']);
    $this->assertArrayNotHasKey('endpoint', $testForm['provider_settings']);
    $this->assertSame(
      $testForm['provider_settings'],
      $formObject->ajaxProviderSettings($testForm, $testState),
    );
  }

  /**
   * Tests provider forms do not expose or persist runtime overrides.
   */
  public function testProviderSettingsUseOverrideFreeConfiguration(): void {
    $this->config('hear_me.provider.piper')
      ->set('endpoint', 'https://stored.example.com/tts')
      ->save();
    $this->container->get('config.factory')
      ->get('hear_me.provider.piper')
      ->setSettingsOverride(['endpoint' => 'https://override.example.com/tts']);
    $formObject = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(HearMeSettingsForm::class);
    $formState = new FormState();

    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);

    $this->assertSame('https://stored.example.com/tts', $form['provider_settings']['endpoint']['#default_value']);
    $this->assertSame('https://override.example.com/tts', $this->container->get('config.factory')->get('hear_me.provider.piper')->get('endpoint'));
    $this->assertSame('https://stored.example.com/tts', $this->config('hear_me.provider.piper')->get('endpoint'));
  }

  /**
   * Tests the settings form structure relied on by callbacks and submissions.
   */
  public function testSettingsFormStructureContracts(): void {
    $this->drupalCreateContentType([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $formObject = $this->container->get('class_resolver')
      ->getInstanceFromDefinition(HearMeSettingsForm::class);
    $formState = (new FormState())->set('hear_me_backfill_confirmation', [
      'field_name' => 'field_tts_audio',
      'bundles' => ['article'],
      'bundle_labels' => ['article' => 'Article'],
      'source_fields' => ['article' => ['title' => TRUE, 'fields' => []]],
      'published_only' => TRUE,
      'missing_only' => TRUE,
      'include_unpublished' => FALSE,
      'requeue_existing' => FALSE,
      'confirm_unpublished_public_audio' => FALSE,
      'candidate_count' => 0,
    ]);

    $form = $this->container->get('form_builder')->buildForm($formObject, $formState);

    $expectedOrder = [
      'setup_status',
      'provider',
      'cache_enabled',
      'runtime_cache',
      'rate_limits',
      'max_request_bytes',
      'max_text_length',
      'tts_audio_field',
      'queue_bundles',
      'queue_source_fields',
      'queue_generated_audio_public_warning',
      'replace_existing_generated_audio',
      'overwrite_manual_audio',
      'audio_field_setup',
      'existing_content_queue',
      'provider_settings',
    ];
    $this->assertSame($expectedOrder, array_values(array_intersect(array_keys($form), $expectedOrder)));

    $this->assertSame('::ajaxProviderSettings', $form['provider']['#ajax']['callback']);
    $this->assertSame('provider-settings-wrapper', $form['provider']['#ajax']['wrapper']);
    $this->assertSame(['runtime_cache_scheme'], $form['runtime_cache']['runtime_cache_scheme']['#parents']);
    $this->assertSame([], $form['runtime_cache']['clear_runtime_cache']['#limit_validation_errors']);
    $this->assertSame(['rate_limit_window_seconds'], $form['rate_limits']['rate_limit_window_seconds']['#parents']);

    $this->assertTrue($form['queue_source_fields']['#tree']);
    $this->assertSame(
      [':input[name="queue_bundles[article]"]' => ['checked' => TRUE]],
      $form['queue_source_fields']['article']['#states']['visible'],
    );
    $this->assertSame(
      ['queue_source_fields', 'article', 'title'],
      $form['queue_source_fields']['article']['title']['#parents'],
    );

    $this->assertTrue($form['audio_field_setup']['#tree']);
    $this->assertSame(
      [
        ['tts_audio_field'],
        ['audio_field_setup', 'bundles'],
      ],
      $form['audio_field_setup']['create_audio_field']['#limit_validation_errors'],
    );

    $this->assertTrue($form['existing_content_queue']['#tree']);
    $this->assertSame(
      [
        ['tts_audio_field'],
        ['queue_bundles'],
        ['queue_source_fields'],
        ['existing_content_queue'],
      ],
      $form['existing_content_queue']['confirm_queue_existing_content']['#limit_validation_errors'],
    );
    $this->assertSame('::queueExistingContentSubmit', $form['existing_content_queue']['confirm_queue_existing_content']['#submit'][0]);
    $this->assertSame([], $form['existing_content_queue']['cancel_queue_existing_content']['#limit_validation_errors']);

    $this->assertTrue($form['provider_settings']['#tree']);
    $this->assertSame('provider-settings-wrapper', $form['provider_settings']['#attributes']['id']);
    $this->assertSame(['provider_settings', 'endpoint'], $form['provider_settings']['endpoint']['#parents']);
  }

}
