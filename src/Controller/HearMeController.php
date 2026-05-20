<?php

namespace Drupal\hear_me\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\hear_me\Service\HearMeInputValidator;
use Drupal\hear_me\Service\HearMeRateLimiter;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HearMeController extends ControllerBase {

  protected HearMeService $ttsService;

  protected HearMeInputValidator $inputValidator;

  protected HearMeRateLimiter $rateLimiter;

  public function __construct(HearMeService $ttsService, HearMeInputValidator $inputValidator, HearMeRateLimiter $rateLimiter) {
    $this->ttsService = $ttsService;
    $this->inputValidator = $inputValidator;
    $this->rateLimiter = $rateLimiter;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hear_me.service'),
      $container->get('hear_me.input_validator'),
      $container->get('hear_me.rate_limiter'),
    );
  }

  public function synthesize(Request $request): Response {
    $validation = $this->inputValidator->validateRequestBody($request->getContent());
    if (!$validation->isValid()) {
      return new Response($validation->errorMessage, 400);
    }

    $providerKey = $this->ttsService->getProviderKey();
    $rateLimitError = $this->rateLimiter->check($providerKey);
    if ($rateLimitError !== NULL) {
      return new Response($rateLimitError, 429);
    }

    $this->rateLimiter->register($providerKey);

    $source = $this->ttsService->getTrustedRuntimeSource(
      $validation->text,
      $validation->lang,
      $validation->source,
      $validation->cacheToken,
    );
    $audio = $this->ttsService->getAudio($validation->text, $validation->lang, $source);
    if ($audio === NULL) {
      return new Response('Synthesis failed', 500);
    }

    $response = new Response($audio->bytes);
    $response->headers->set('Content-Type', $audio->mimeType);
    $response->headers->set('Content-Disposition', 'inline; filename="tts.' . $audio->extension . '"');
    $response->headers->set('Content-Length', (string) strlen($audio->bytes));
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    return $response;
  }

}
