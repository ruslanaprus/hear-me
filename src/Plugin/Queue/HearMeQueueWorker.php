<?php

namespace Drupal\hear_me\Plugin\Queue;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued TTS synthesis jobs.
 */
#[QueueWorker(
  id: 'hear_me_tts',
  title: new TranslatableMarkup('HearMe TTS Queue Worker'),
  cron: ['time' => 30],
)]
class HearMeQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected HearMeService $ttsService;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    HearMeService $ttsService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->ttsService = $ttsService;
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('hear_me.service'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $text = $data['text'] ?? '';
    $lang = $data['lang'] ?? $this->ttsService->getDefaultLang();
    $nid  = $data['nid'] ?? NULL;

    if (!$text) {
      return;
    }

    $media = $this->ttsService->synthesize($text, $lang);

    if ($media && $nid) {
      $this->ttsService->attachMediaToNode((int) $nid, $media);
    }
  }

}
