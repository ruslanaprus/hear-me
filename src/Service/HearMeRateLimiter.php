<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Applies Flood API throttles and quotas to TTS generation requests.
 */
class HearMeRateLimiter {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected FloodInterface $flood,
    protected AccountProxyInterface $currentUser,
    protected RequestStack $requestStack,
  ) {}

  public function check(string $providerKey): ?string {
    foreach ($this->getChecks($providerKey) as $check) {
      if ($check['limit'] <= 0) {
        continue;
      }

      if (!$this->flood->isAllowed($check['event'], $check['limit'], $check['window'], $check['identifier'])) {
        return $check['message'];
      }
    }

    return NULL;
  }

  public function register(string $providerKey): void {
    foreach ($this->getChecks($providerKey) as $check) {
      if ($check['limit'] <= 0) {
        continue;
      }

      $this->flood->register($check['event'], $check['window'], $check['identifier']);
    }
  }

  protected function getChecks(string $providerKey): array {
    $safeProvider = preg_replace('/[^a-z0-9_:-]/', '_', strtolower($providerKey)) ?: 'unknown';
    $window = $this->getConfigInt('rate_limit_window_seconds', 60);
    $window = max(1, $window);

    return [
      [
        'event' => 'hear_me.tts.user.' . $safeProvider,
        'identifier' => $this->getUserIdentifier(),
        'limit' => $this->getConfigInt('rate_limit_user_requests', 20),
        'window' => $window,
        'message' => 'Too many text-to-speech requests. Please wait before trying again.',
      ],
      [
        'event' => 'hear_me.tts.ip.' . $safeProvider,
        'identifier' => $this->getIpIdentifier(),
        'limit' => $this->getConfigInt('rate_limit_ip_requests', 60),
        'window' => $window,
        'message' => 'Too many text-to-speech requests from this network. Please wait before trying again.',
      ],
      [
        'event' => 'hear_me.tts.role.' . $safeProvider,
        'identifier' => $this->getRoleIdentifier(),
        'limit' => $this->getConfigInt('rate_limit_role_requests', 0),
        'window' => $window,
        'message' => 'Text-to-speech is temporarily busy for this role. Please try again later.',
      ],
      [
        'event' => 'hear_me.tts.daily.' . $safeProvider,
        'identifier' => $this->getUserIdentifier(),
        'limit' => $this->getConfigInt('daily_user_quota', 500),
        'window' => 86400,
        'message' => 'Daily text-to-speech quota exceeded. Please try again tomorrow.',
      ],
      [
        'event' => 'hear_me.tts.monthly.' . $safeProvider,
        'identifier' => $this->getUserIdentifier(),
        'limit' => $this->getConfigInt('monthly_user_quota', 5000),
        'window' => 2592000,
        'message' => 'Monthly text-to-speech quota exceeded.',
      ],
    ];
  }

  protected function getUserIdentifier(): string {
    if ($this->currentUser->isAuthenticated()) {
      return 'uid:' . $this->currentUser->id() . ':roles:' . $this->getRoleHash();
    }

    return 'anon:' . $this->getIpIdentifier() . ':roles:' . $this->getRoleHash();
  }

  protected function getIpIdentifier(): string {
    return 'ip:' . ($this->requestStack->getCurrentRequest()?->getClientIp() ?: 'unknown');
  }

  protected function getRoleIdentifier(): string {
    return 'roles:' . $this->getRoleHash();
  }

  protected function getRoleHash(): string {
    $roles = $this->currentUser->getRoles();
    sort($roles);
    return hash('sha256', implode(',', $roles));
  }

  protected function getConfigInt(string $key, int $default): int {
    $value = $this->configFactory->get('hear_me.settings')->get($key);
    return is_numeric($value) ? max(0, (int) $value) : $default;
  }

}
