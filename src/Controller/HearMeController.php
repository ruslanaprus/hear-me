<?php

namespace Drupal\hear_me\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\hear_me\Service\HearMeInputValidator;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HearMeController extends ControllerBase {

  protected HearMeService $ttsService;

  protected HearMeInputValidator $inputValidator;

  public function __construct(HearMeService $ttsService, HearMeInputValidator $inputValidator) {
    $this->ttsService = $ttsService;
    $this->inputValidator = $inputValidator;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hear_me.service'),
      $container->get('hear_me.input_validator'),
    );
  }

  public function synthesize(Request $request): Response {
    $validation = $this->inputValidator->validateRequestBody($request->getContent());
    if (!$validation->isValid()) {
      return new Response($validation->errorMessage, 400);
    }

    $audio = $this->ttsService->getAudio($validation->text, $validation->lang);
    if ($audio === NULL) {
      return new Response('Synthesis failed', 500);
    }

    $response = new Response($audio->bytes);
    $response->headers->set('Content-Type', $audio->mimeType);
    $response->headers->set('Content-Disposition', 'inline; filename="tts.' . $audio->extension . '"');

    return $response;
  }

}
