<?php

namespace Drupal\hear_me\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\hear_me\Service\HearMeService;

class HearMeController extends ControllerBase {

  protected HearMeService $ttsService;

  public function __construct(HearMeService $ttsService) {
    $this->ttsService = $ttsService;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hear_me.service')
    );
  }

  public function synthesize(Request $request): Response {
    $data = json_decode($request->getContent(), TRUE);
    $text = $data['text'] ?? '';
    $lang = $data['lang'] ?? $this->ttsService->getDefaultLang();

    if (!$text) {
      return new Response('Missing text', 400);
    }

    $media = $this->ttsService->synthesize($text, $lang);
    if (!$media) {
      return new Response('Synthesis failed', 500);
    }

    $file = $media->get('field_media_audio_file')->entity;
    $uri = $file->getFileUri();
    $realpath = \Drupal::service('file_system')->realpath($uri);

    $response = new Response(file_get_contents($realpath));
    $response->headers->set('Content-Type', 'audio/wav');
    $response->headers->set('Content-Disposition', 'inline; filename="tts.wav"');

    return $response;
  }
}
