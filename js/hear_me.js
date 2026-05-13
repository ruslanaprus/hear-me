(function (Drupal, drupalSettings, once) {

  /**
   * Resolve the effective TTS language from drupalSettings, with a fallback.
   */
  function resolveLang(settings, dataLang) {
    return dataLang
      || (settings.hear_me && settings.hear_me.default_lang)
      || 'en';
  }

  function getStatusElement(anchorEl) {
    let statusEl = anchorEl.nextElementSibling;
    while (statusEl && !statusEl.classList.contains('hear-me-status')) {
      statusEl = statusEl.nextElementSibling;
    }

    if (!statusEl) {
      statusEl = document.createElement('span');
      statusEl.className = 'hear-me-status';
      statusEl.setAttribute('role', 'status');
      statusEl.setAttribute('aria-live', 'polite');
      statusEl.hidden = true;
      anchorEl.insertAdjacentElement('afterend', statusEl);
    }

    return statusEl;
  }

  function showStatus(anchorEl, message, type) {
    const statusEl = getStatusElement(anchorEl);
    const statusType = type || 'status';
    statusEl.className = 'hear-me-status hear-me-status--' + statusType;
    statusEl.textContent = message;
    statusEl.hidden = false;
  }

  function clearStatus(anchorEl) {
    const statusEl = getStatusElement(anchorEl);
    statusEl.textContent = '';
    statusEl.hidden = true;
  }

  /**
   * POST text to the TTS endpoint and play the returned audio blob.
   *
   * Fetches a Drupal CSRF token first so the route's _csrf_token requirement
   * is satisfied. The token is cached per page-load via a module-level
   * variable so repeated button clicks only incur one extra request.
   *
   * @param {string} text - Plain text to synthesise.
   * @param {string} lang - BCP-47 language code.
   * @param {HTMLAudioElement} audioEl - The <audio> element to play into.
   */
  var csrfTokenPromise = null;

  function getCsrfToken() {
    if (!csrfTokenPromise) {
      const tokenUrl = drupalSettings.hear_me?.csrf_token_url || Drupal.url('session/token');
      csrfTokenPromise = fetch(tokenUrl)
        .then(function (res) { return res.text(); });
    }
    return csrfTokenPromise;
  }

  function fetchAndPlay(text, lang, audioEl) {
    const ttsUrl = drupalSettings.hear_me?.tts_url || Drupal.url('hear-me/tts');

    showStatus(audioEl, Drupal.t('Generating audio...'));

    getCsrfToken()
      .then(function (token) {
        return fetch(ttsUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
          },
          body: JSON.stringify({ text: text, lang: lang }),
        });
      })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('TTS request failed with status ' + res.status);
        }
        return res.blob();
      })
      .then(function (blob) {
        const url = URL.createObjectURL(blob);
        audioEl.src = url;
        audioEl.hidden = false;
        return audioEl.play();
      })
      .then(function () {
        clearStatus(audioEl);
      })
      .catch(function () {
        showStatus(audioEl, Drupal.t('Audio playback could not be started. Please try again.'), 'error');
      });
  }

  Drupal.behaviors.hearMeTts = {
    attach: function (context, settings) {

      once('hearMeTts', '.tts-play', context).forEach(function (el) {
        el.addEventListener('click', function () {
          const text = el.dataset.text;
          const lang = resolveLang(settings, el.dataset.lang);
          const audioEl = el.parentElement?.querySelector('.tts-audio');

          if (!audioEl) {
            showStatus(el, Drupal.t('Audio player is not available for this text.'), 'error');
            return;
          }

          fetchAndPlay(text, lang, audioEl);
        });
      });

      once('hearMeBlock', '[data-action="tts-page"]', context).forEach(function (el) {
        const audioEl = document.createElement('audio');
        audioEl.className = 'hear-me-block-audio';
        audioEl.controls  = true;
        audioEl.hidden    = true;
        el.insertAdjacentElement('afterend', audioEl);

        el.addEventListener('click', function () {
          const lang = resolveLang(settings, null);

          const sourceEl = document.querySelector('main') || document.body;
          const clone    = sourceEl.cloneNode(true);
          clone.querySelectorAll([
            '.contextual',
            '[data-action="tts-page"]',
            '.hear-me-block-audio',
            '.node__meta',
            '.teaser__meta',
          ].join(', ')).forEach(function (node) { node.remove(); });

          const text = clone.innerText
            .split('\n')
            .map(function (l) { return l.trim(); })
            .filter(function (l) { return l.length > 0; })
            .join('\n');

          if (!text) {
            showStatus(audioEl, Drupal.t('No readable page text was found.'), 'warning');
            return;
          }

          fetchAndPlay(text, lang, audioEl);
        });
      });
    }
  };
})(Drupal, drupalSettings, once);
