(function ($, Drupal, once) {
  Drupal.behaviors.hearMeTts = {
    attach: function (context, settings) {
      once('hearMeTts', '.tts-play', context).forEach(function (el) {
        $(el).on('click', function () {
          const text   = $(this).data('text');
          const lang   = $(this).data('lang')
                           || (settings.hear_me && settings.hear_me.default_lang)
                           || 'en';
          const audioEl = $(this).siblings('.tts-audio')[0];

          if (!audioEl) {
            console.error('HearMe: no .tts-audio sibling found for button', el);
            return;
          }

          fetch('/hear-me/tts', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: text, lang: lang })
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
        });
      });
    }
  };
})(jQuery, Drupal, once);
