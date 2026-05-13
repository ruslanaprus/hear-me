<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\hear_me\TtsInputValidationResult;

/**
 * Validates and normalizes TTS endpoint input.
 */
class HearMeInputValidator {

  public const DEFAULT_MAX_REQUEST_BYTES = 32768;

  public const DEFAULT_MAX_TEXT_LENGTH = 5000;

  public const MIN_REQUEST_BYTES = 1024;

  public const MIN_TEXT_LENGTH = 1;

  public const ABSOLUTE_MAX_REQUEST_BYTES = 262144;

  public const ABSOLUTE_MAX_TEXT_LENGTH = 20000;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected HearMeService $ttsService,
  ) {}

  public function validateRequestBody(string $content): TtsInputValidationResult {
    if (strlen($content) > $this->getMaxRequestBytes()) {
      return TtsInputValidationResult::invalid('Request body too large');
    }

    $data = json_decode($content, TRUE);
    if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
      return TtsInputValidationResult::invalid('Invalid JSON payload');
    }

    $text = $this->normalizeText((string) ($data['text'] ?? ''));
    if ($text === '') {
      return TtsInputValidationResult::invalid('Missing text');
    }

    if (mb_strlen($text) > $this->getMaxTextLength()) {
      return TtsInputValidationResult::invalid('Text is too long');
    }

    $lang = $this->normalizeLang((string) ($data['lang'] ?? ''));
    if ($lang === '') {
      $lang = $this->normalizeLang($this->ttsService->getDefaultLang());
    }

    $resolvedLang = $this->resolveSupportedLanguage($lang);
    if ($resolvedLang === NULL) {
      return TtsInputValidationResult::invalid('Unsupported language');
    }

    return TtsInputValidationResult::valid($text, $resolvedLang);
  }

  private function getMaxRequestBytes(): int {
    return $this->getBoundedConfigInt(
      'max_request_bytes',
      self::DEFAULT_MAX_REQUEST_BYTES,
      self::MIN_REQUEST_BYTES,
      self::ABSOLUTE_MAX_REQUEST_BYTES,
    );
  }

  private function getMaxTextLength(): int {
    return $this->getBoundedConfigInt(
      'max_text_length',
      self::DEFAULT_MAX_TEXT_LENGTH,
      self::MIN_TEXT_LENGTH,
      self::ABSOLUTE_MAX_TEXT_LENGTH,
    );
  }

  private function getBoundedConfigInt(string $key, int $default, int $min, int $max): int {
    $value = $this->configFactory->get('hear_me.settings')->get($key);
    if (!is_numeric($value)) {
      return $default;
    }

    return max($min, min((int) $value, $max));
  }

  private function normalizeText(string $text): string {
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace('/[ \t\r\n]+/u', ' ', $text) ?? $text;
    return trim($text);
  }

  private function normalizeLang(string $lang): string {
    return strtolower(str_replace('_', '-', trim($lang)));
  }

  private function resolveSupportedLanguage(string $lang): ?string {
    foreach ($this->ttsService->getSupportedLanguages() as $supportedLang) {
      if ($this->normalizeLang($supportedLang) === $lang) {
        return $supportedLang;
      }
    }

    $shortCode = substr($lang, 0, 2);
    foreach ($this->ttsService->getSupportedLanguages() as $supportedLang) {
      if ($this->normalizeLang($supportedLang) === $shortCode) {
        return $supportedLang;
      }
    }

    return NULL;
  }

}
