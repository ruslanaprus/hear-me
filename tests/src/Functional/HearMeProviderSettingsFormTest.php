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

}
