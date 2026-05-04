(function ($, Drupal, once) {
  Drupal.behaviors.hearMeTts = {
    attach: function (context, settings) {
      once('hearMeTts', '.tts-play', context).forEach(function (el) {
        $(el).on('click', function () {
          const text = $(this).data('text');
          const lang = settings.hear_me?.default_lang || 'en';
          const audioEl = $(this).siblings('.tts-audio')[0];

          fetch('/hear-me/tts', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: text, lang: lang })
          })
            .then(res => {
              if (!res.ok) {
                throw new Error('TTS request failed with status ' + res.status);
              }
              return res.blob();
            })
            .then(blob => {
              const url = URL.createObjectURL(blob);
              audioEl.src = url;
              audioEl.hidden = false;
              audioEl.play();
            })
            .catch(err => console.error('TTS error', err));
        });
      });
    }
  };
})(jQuery, Drupal, once);
