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
    $this->assertSame('Piper (self-hosted)', $piperForm['provider']['#options']['piper']);
    $this->assertSame('Test provider', $piperForm['provider']['#options']['test']);
    $this->assertSame('::ajaxProviderSettings', $piperForm['provider']['#ajax']['callback']);
    $this->assertSame('provider-settings-wrapper', $piperForm['provider']['#ajax']['wrapper']);
    $this->assertSame('provider-settings-wrapper', $piperForm['provider_settings']['#attributes']['id']);
    $this->assertArrayHasKey('endpoint', $piperForm['provider_settings']);

    $testState = (new FormState())->setValue('provider', 'test');
    $testForm = $this->container->get('form_builder')->buildForm($formObject, $testState);
    $this->assertSame('provider-settings-wrapper', $testForm['provider_settings']['#attributes']['id']);
    $this->assertArrayNotHasKey('endpoint', $testForm['provider_settings']);
    $this->assertSame(
      $testForm['provider_settings'],
      $formObject->ajaxProviderSettings($testForm, $testState),
    );
  }

}
