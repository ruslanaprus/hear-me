<?php

namespace Drupal\hear_me\Plugin\Queue\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued TTS synthesis jobs.
 *
 * @QueueWorker(
 *   id = "hear_me_tts",
 *   title = @Translation("HearMe TTS Queue Worker"),
 *   cron = {"time" = 30}
 * )
 */
class HearMeQueueWorker extends QueueWorkerBase {

  protected HearMeService $ttsService;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(HearMeService $ttsService, EntityTypeManagerInterface $entityTypeManager) {
    $this->ttsService = $ttsService;
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('hear_me.service'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $text = $data['text'] ?? '';
    $lang = $data['lang'] ?? $this->ttsService->getDefaultLang();
    $nid  = $data['nid'] ?? NULL;

    if (!$text) {
      return;
    }

    $media = $this->ttsService->synthesize($text, $lang);

    if ($media && $nid) {
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if ($node && $node->hasField('field_tts_audio')) {
        $node->set('field_tts_audio', $media->id());
        $node->save();
      }
    }
  }
}
