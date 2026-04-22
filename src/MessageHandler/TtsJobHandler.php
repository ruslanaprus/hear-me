<?php

namespace Drupal\hear_me\MessageHandler;

use Drupal\hear_me\Message\TtsJobMessage;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class TtsJobHandler implements MessageHandlerInterface {

  protected HearMeService $ttsService;

  public function __construct(HearMeService $ttsService) {
    $this->ttsService = $ttsService;
  }

  public function __invoke(TtsJobMessage $message) {
    $media = $this->ttsService->synthesize($message->text, $message->lang);

    if ($media) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($message->nid);
      if ($node) {
        $node->set('field_tts_audio', $media->id());
        $node->save();
      }
    }
  }
}
