<?php

namespace Drupal\hear_me\Controller;

use Drupal\Core\Access\CsrfRequestHeaderAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HearMeController extends ControllerBase {

  protected HearMeService $ttsService;
  protected CsrfTokenGenerator $csrfToken;

  public function __construct(HearMeService $ttsService, CsrfTokenGenerator $csrfToken) {
    $this->ttsService = $ttsService;
    $this->csrfToken  = $csrfToken;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hear_me.service'),
      $container->get('csrf_token'),
    );
  }

  public function synthesize(Request $request): Response {
    $token = $request->headers->get('X-CSRF-Token', '');
    if (!$this->csrfToken->validate($token, CsrfRequestHeaderAccessCheck::TOKEN_KEY)) {
      return new Response('CSRF token mismatch', 403);
    }

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
