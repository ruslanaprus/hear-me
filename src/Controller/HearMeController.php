<?php

namespace Drupal\hear_me\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HearMeController extends ControllerBase {

  protected HearMeService $ttsService;

  public function __construct(HearMeService $ttsService) {
    $this->ttsService = $ttsService;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hear_me.service'),
    );
  }

  public function synthesize(Request $request): Response {
    $data = json_decode($request->getContent(), TRUE);
    $text = $data['text'] ?? '';
    $lang = $data['lang'] ?? $this->ttsService->getDefaultLang();

    if (!$text) {
      return new Response('Missing text', 400);
    }

    $bytes = $this->ttsService->getAudioBytes($text, $lang);
    if ($bytes === NULL) {
      return new Response('Synthesis failed', 500);
    }

    $response = new Response($bytes);
    $response->headers->set('Content-Type', 'audio/wav');
    $response->headers->set('Content-Disposition', 'inline; filename="tts.wav"');

    return $response;
  }

}
