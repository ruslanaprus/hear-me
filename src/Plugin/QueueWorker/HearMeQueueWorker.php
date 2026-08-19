<?php

namespace Drupal\hear_me\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hear_me\Service\HearMeNodeAudioQueue;
use Drupal\hear_me\Service\HearMeService;
use Drupal\hear_me\Service\NodeAudioAttacher;
use Drupal\hear_me\Service\TtsProviderResolver;
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

  protected NodeAudioAttacher $nodeAudioAttacher;

  protected HearMeNodeAudioQueue $nodeAudioQueue;

  protected TtsProviderResolver $providerResolver;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    HearMeService $ttsService,
    NodeAudioAttacher $nodeAudioAttacher,
    HearMeNodeAudioQueue $nodeAudioQueue,
    TtsProviderResolver $providerResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->ttsService = $ttsService;
    $this->nodeAudioAttacher = $nodeAudioAttacher;
    $this->nodeAudioQueue = $nodeAudioQueue;
    $this->providerResolver = $providerResolver;
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
      $container->get('hear_me.node_audio_attacher'),
      $container->get('hear_me.node_audio_queue'),
      $container->get('hear_me.provider_resolver'),
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

    $providerId = $this->providerResolver->getActiveProviderId();
    $queuedHash = (string) ($data['content_hash'] ?? '');
    if ($queuedHash === '' && !empty($data['text'])) {
      $lang = $data['lang'] ?? NULL;
      if ($lang === NULL) {
        $lang = $this->providerResolver->getDefaultLanguage($providerId);
      }
      $queuedHash = $this->nodeAudioQueue->buildContentHash(
        (string) $data['text'],
        (string) $lang,
      );
    }

    if ($queuedHash === '') {
      return;
    }

    $current = $this->nodeAudioQueue->buildCurrentQueueItem($nid, $providerId);
    if ($current === NULL) {
      $this->nodeAudioQueue->clearQueuedHash($nid, $queuedHash);
      return;
    }

    if (!hash_equals($current['content_hash'], $queuedHash)) {
      $this->nodeAudioQueue->clearQueuedHash($nid, $queuedHash);
      return;
    }

    $media = $this->ttsService->synthesize($current['text'], $current['lang'], $providerId);

    if ($media) {
      $this->nodeAudioAttacher->attach($nid, $media);
    }

    $this->nodeAudioQueue->clearQueuedHash($nid, $queuedHash);
  }

}
