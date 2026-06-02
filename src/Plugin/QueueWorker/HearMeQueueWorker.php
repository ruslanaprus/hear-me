<?php

namespace Drupal\hear_me\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hear_me\Service\HearMeNodeAudioQueue;
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

  protected HearMeNodeAudioQueue $nodeAudioQueue;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    HearMeService $ttsService,
    HearMeNodeAudioQueue $nodeAudioQueue,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->ttsService = $ttsService;
    $this->nodeAudioQueue = $nodeAudioQueue;
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
      $container->get('hear_me.node_audio_queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = (int) ($data['nid'] ?? 0);
    if ($nid <= 0) {
      return;
    }

    $current = $this->nodeAudioQueue->buildCurrentQueueItem($nid);
    if ($current === NULL) {
      return;
    }

    $queuedHash = (string) ($data['content_hash'] ?? '');
    if ($queuedHash === '' && !empty($data['text'])) {
      $queuedHash = $this->nodeAudioQueue->buildContentHash(
        (string) $data['text'],
        (string) ($data['lang'] ?? $this->ttsService->getDefaultLang()),
      );
    }

    if ($queuedHash === '' || !hash_equals($current['content_hash'], $queuedHash)) {
      return;
    }

    $media = $this->ttsService->synthesize($current['text'], $current['lang']);

    if ($media) {
      $this->ttsService->attachMediaToNode($nid, $media);
    }
  }

}
