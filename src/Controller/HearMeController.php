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

  public function __construct(
    HearMeService $ttsService,
    HearMeInputValidator $inputValidator,
    HearMeRateLimiter $rateLimiter,
  ) {
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
    $requestLimitError = $this->rateLimiter->check('request', TRUE);
    if ($requestLimitError !== NULL) {
      return $this->noStoreResponse($requestLimitError, 429);
    }
    $this->rateLimiter->register('request', TRUE);

    $contentType = $request->headers->get('Content-Type', '');
    if (!preg_match('/^application\/(?:[a-z0-9!#$&^_.+-]+\+)?json(?:\s*;|$)/i', $contentType)) {
      return $this->noStoreResponse('Content-Type must be application/json', 415);
    }

    $maxRequestBytes = $this->inputValidator->getMaxRequestBytes();
    $contentLength = $request->headers->get('Content-Length');
    if (is_numeric($contentLength) && (int) $contentLength > $maxRequestBytes) {
      return $this->noStoreResponse('Request body too large', 413);
    }

    $stream = $request->getContent(TRUE);
    $content = is_resource($stream) ? stream_get_contents($stream, $maxRequestBytes + 1) : FALSE;
    if (!is_string($content)) {
      return $this->noStoreResponse('Invalid request body', 400);
    }
    if (strlen($content) > $maxRequestBytes) {
      return $this->noStoreResponse('Request body too large', 413);
    }

    $validation = $this->inputValidator->validateRequestBody($content);
    if (!$validation->isValid()) {
      return $this->noStoreResponse($validation->errorMessage, 400);
    }

    $providerId = $validation->providerId;
    $rateLimitError = $this->rateLimiter->check($providerId);
    if ($rateLimitError !== NULL) {
      return $this->noStoreResponse($rateLimitError, 429);
    }

    $this->rateLimiter->register($providerId);

    $source = $this->ttsService->getTrustedRuntimeSource(
      $validation->text,
      $validation->lang,
      $validation->source,
      $validation->cacheToken,
      $providerId,
    );
    $audio = $this->ttsService->getAudio($validation->text, $validation->lang, $source, $providerId);
    if ($audio === NULL) {
      return $this->noStoreResponse('Synthesis failed', 500);
    }

    $response = $this->noStoreResponse($audio->bytes);
    $response->headers->set('Content-Type', $audio->mimeType);
    $response->headers->set('Content-Disposition', 'inline; filename="tts.' . $audio->extension . '"');
    $response->headers->set('Content-Length', (string) strlen($audio->bytes));
    $response->headers->set('X-Content-Type-Options', 'nosniff');

    return $response;
  }

  protected function noStoreResponse(string $content, int $status = 200): Response {
    $response = new Response($content, $status);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
    $response->headers->set('Pragma', 'no-cache');
    $response->headers->set('Expires', '0');
    return $response;
  }

}
