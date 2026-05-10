(function ($, Drupal, once) {

  /**
   * Resolve the effective TTS language from drupalSettings, with a fallback.
   */
  function resolveLang(settings, dataLang) {
    return dataLang
      || (settings.hear_me && settings.hear_me.default_lang)
      || 'en';
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
      csrfTokenPromise = fetch('/session/token')
        .then(function (res) { return res.text(); });
    }
    return csrfTokenPromise;
  }

  function fetchAndPlay(text, lang, audioEl) {
    getCsrfToken()
      .then(function (token) {
        return fetch('/hear-me/tts', {
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
        audioEl.play();
      })
      .catch(function (err) {
        console.error('HearMe TTS error:', err);
      });
  }

  Drupal.behaviors.hearMeTts = {
    attach: function (context, settings) {

      once('hearMeTts', '.tts-play', context).forEach(function (el) {
        $(el).on('click', function () {
          const text    = $(this).data('text');
          const lang    = resolveLang(settings, $(this).data('lang'));
          const audioEl = $(this).siblings('.tts-audio')[0];

          if (!audioEl) {
            console.error('HearMe: no .tts-audio sibling found for button', el);
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

        $(el).on('click', function () {
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
            console.warn('HearMe: no page text found to synthesise.');
            return;
          }

          fetchAndPlay(text, lang, audioEl);
        });
      });
    }
  };
})(jQuery, Drupal, once);
