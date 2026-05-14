(function (Drupal, drupalSettings, once) {

  const PRIMARY_SCOPE_SELECTOR_GROUPS = [
    ['article.node--view-mode-full .node__content'],
    ['article.node--view-mode-full'],
    ['article.node--view-mode-teaser'],
    ['[role="main"] .node__content'],
    ['main[role="main"]'],
    ['main'],
  ];

  const OPTIONAL_SCOPE_SELECTORS = {
    comments: ['#comments', 'article.comment', '.comment'],
    sidebar: ['aside.region--sidebar', '.region--sidebar'],
    menu: ['header nav', '.region--primary-menu', '.region--secondary-menu', '.menu'],
  };

  const OPTIONAL_SCOPE_SELECTOR_TEXT = {
    comments: OPTIONAL_SCOPE_SELECTORS.comments.join(', '),
    sidebar: OPTIONAL_SCOPE_SELECTORS.sidebar.join(', '),
    menu: OPTIONAL_SCOPE_SELECTORS.menu.join(', '),
  };

  const SECTION_CANDIDATE_DESCENDANT_SELECTORS = [
    'p',
    'li',
    'blockquote',
    'figcaption',
    'h1',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'pre',
    'td',
    'th',
  ];

  const NOISE_SELECTORS = [
    '.contextual',
    '[data-action="tts-page"]',
    '[data-action="tts-select-section"]',
    '.tts-play',
    '.tts-audio',
    '.hear-me-block-audio',
    '.hear-me-status',
    '.hear-me-selection-controls',
    '.node__meta',
    '.teaser__meta',
    '.visually-hidden',
    '.sr-only',
    'script',
    'style',
    'noscript',
    '[hidden]',
    '[aria-hidden="true"]',
    '[data-hear-me-control="true"]',
  ];

  let activeSelectionState = null;

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
      statusEl.setAttribute('data-hear-me-control', 'true');
      statusEl.hidden = true;
      anchorEl.insertAdjacentElement('afterend', statusEl);
    }

    statusEl.setAttribute('data-hear-me-control', 'true');

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
        const existingObjectUrl = audioEl.dataset.hearMeObjectUrl;
        if (existingObjectUrl) {
          URL.revokeObjectURL(existingObjectUrl);
        }

        const url = URL.createObjectURL(blob);
        audioEl.dataset.hearMeObjectUrl = url;
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

  function normalizeReadableText(text) {
    if (!text) {
      return '';
    }

    return text
      .split('\n')
      .map(function (line) {
        return line.replace(/\s+/g, ' ').trim();
      })
      .filter(function (line) {
        return line.length > 0;
      })
      .join('\n')
      .trim();
  }

  function getSelectedText() {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || selection.isCollapsed) {
      return '';
    }

    return normalizeReadableText(selection.toString());
  }

  function isVisibleElement(element) {
    return !!(element && element.getClientRects && element.getClientRects().length);
  }

  function isInBlockedScope(element, scopeOptions) {
    if (!element || !(element instanceof Element)) {
      return false;
    }

    if (!scopeOptions.includeComments && element.closest(OPTIONAL_SCOPE_SELECTOR_TEXT.comments)) {
      return true;
    }

    if (!scopeOptions.includeSidebar && element.closest(OPTIONAL_SCOPE_SELECTOR_TEXT.sidebar)) {
      return true;
    }

    if (!scopeOptions.includeMenu && element.closest(OPTIONAL_SCOPE_SELECTOR_TEXT.menu)) {
      return true;
    }

    return false;
  }

  function hasReadableText(element) {
    const rawText = element.innerText || element.textContent || '';
    return normalizeReadableText(rawText).length > 0;
  }

  function isCandidateElement(element, controlRoot, scopeOptions) {
    if (!element || !(element instanceof Element)) {
      return false;
    }

    if (!isVisibleElement(element) || !hasReadableText(element)) {
      return false;
    }

    if (element.closest('[data-hear-me-control="true"]')) {
      return false;
    }

    if (controlRoot && controlRoot.contains(element)) {
      return false;
    }

    if (isInBlockedScope(element, scopeOptions)) {
      return false;
    }

    return true;
  }

  function collectElementsBySelectors(selectors, controlRoot, scopeOptions, options) {
    const results = [];
    const allowNested = !!options?.allowNested;

    selectors.forEach(function (selector) {
      document.querySelectorAll(selector).forEach(function (element) {
        if (!isCandidateElement(element, controlRoot, scopeOptions)) {
          return;
        }

        if (results.includes(element)) {
          return;
        }

        if (!allowNested) {
          if (results.some(function (existing) {
            return existing === element || existing.contains(element);
          })) {
            return;
          }

          if (results.some(function (existing) {
            return element.contains(existing);
          })) {
            return;
          }
        }

        results.push(element);
      });
    });

    return results;
  }

  function collectSectionCandidates(selectors, controlRoot, scopeOptions) {
    const roots = collectElementsBySelectors(
      selectors,
      controlRoot,
      scopeOptions,
      { allowNested: true },
    );

    const candidates = [];

    roots.forEach(function (root) {
      if (!candidates.includes(root)) {
        candidates.push(root);
      }

      SECTION_CANDIDATE_DESCENDANT_SELECTORS.forEach(function (selector) {
        root.querySelectorAll(selector).forEach(function (element) {
          if (!isCandidateElement(element, controlRoot, scopeOptions)) {
            return;
          }

          if (!candidates.includes(element)) {
            candidates.push(element);
          }
        });
      });
    });

    return candidates;
  }

  function getPrimarySelectors(controlRoot) {
    const fullyEnabled = {
      includeComments: true,
      includeSidebar: true,
      includeMenu: true,
    };

    for (const selectorGroup of PRIMARY_SCOPE_SELECTOR_GROUPS) {
      const elements = collectElementsBySelectors(selectorGroup, controlRoot, fullyEnabled);
      if (elements.length) {
        return selectorGroup;
      }
    }

    return ['main[role="main"]', 'main'];
  }

  function extractTextFromElement(sourceEl, scopeOptions) {
    if (!sourceEl || !(sourceEl instanceof Element)) {
      return '';
    }

    const clone = sourceEl.cloneNode(true);
    const selectorsToRemove = NOISE_SELECTORS.slice();

    if (!scopeOptions.includeComments) {
      selectorsToRemove.push(OPTIONAL_SCOPE_SELECTOR_TEXT.comments);
    }

    if (!scopeOptions.includeSidebar) {
      selectorsToRemove.push(OPTIONAL_SCOPE_SELECTOR_TEXT.sidebar);
    }

    if (!scopeOptions.includeMenu) {
      selectorsToRemove.push(OPTIONAL_SCOPE_SELECTOR_TEXT.menu);
    }

    clone.querySelectorAll(selectorsToRemove.join(', ')).forEach(function (node) {
      node.remove();
    });

    return normalizeReadableText(clone.innerText || clone.textContent || '');
  }

  function pushTextChunk(chunks, text) {
    if (!text) {
      return;
    }

    if (chunks.includes(text)) {
      return;
    }

    chunks.push(text);
  }

  function collectDefaultPageText(controlRoot, scopeOptions) {
    const primarySelectors = getPrimarySelectors(controlRoot);
    const includeAllScopes = {
      includeComments: true,
      includeSidebar: true,
      includeMenu: true,
    };

    const contentOnlyScopeOptions = {
      includeComments: false,
      includeSidebar: false,
      includeMenu: false,
    };

    const chunks = [];

    collectElementsBySelectors(primarySelectors, controlRoot, includeAllScopes)
      .forEach(function (element) {
        pushTextChunk(chunks, extractTextFromElement(element, contentOnlyScopeOptions));
      });

    if (scopeOptions.includeComments) {
      collectElementsBySelectors(OPTIONAL_SCOPE_SELECTORS.comments, controlRoot, includeAllScopes)
        .forEach(function (element) {
          pushTextChunk(chunks, extractTextFromElement(element, includeAllScopes));
        });
    }

    if (scopeOptions.includeSidebar) {
      collectElementsBySelectors(OPTIONAL_SCOPE_SELECTORS.sidebar, controlRoot, includeAllScopes)
        .forEach(function (element) {
          pushTextChunk(chunks, extractTextFromElement(element, includeAllScopes));
        });
    }

    if (scopeOptions.includeMenu) {
      collectElementsBySelectors(OPTIONAL_SCOPE_SELECTORS.menu, controlRoot, includeAllScopes)
        .forEach(function (element) {
          pushTextChunk(chunks, extractTextFromElement(element, includeAllScopes));
        });
    }

    return chunks.join('\n\n');
  }

  function createScopeOption(scopeKey, labelText) {
    const optionLabel = document.createElement('label');
    optionLabel.className = 'hear-me-scope-option';
    optionLabel.setAttribute('data-hear-me-control', 'true');

    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'hear-me-scope-checkbox';
    checkbox.setAttribute('data-hear-me-scope', scopeKey);
    checkbox.setAttribute('data-hear-me-control', 'true');

    const text = document.createElement('span');
    text.textContent = labelText;

    optionLabel.appendChild(checkbox);
    optionLabel.appendChild(text);

    return { wrapper: optionLabel, checkbox: checkbox };
  }

  function ensureSelectionControls(statusEl) {
    let controlsRoot = statusEl.nextElementSibling;
    while (controlsRoot && !controlsRoot.classList.contains('hear-me-selection-controls')) {
      controlsRoot = controlsRoot.nextElementSibling;
    }

    if (!controlsRoot) {
      controlsRoot = document.createElement('div');
      controlsRoot.className = 'hear-me-selection-controls';
      controlsRoot.setAttribute('data-hear-me-control', 'true');

      const selectButton = document.createElement('button');
      selectButton.type = 'button';
      selectButton.className = 'hear-me-select-toggle';
      selectButton.setAttribute('data-action', 'tts-select-section');
      selectButton.setAttribute('aria-pressed', 'false');
      selectButton.setAttribute('data-hear-me-control', 'true');
      selectButton.textContent = Drupal.t('Select section to listen');
      controlsRoot.appendChild(selectButton);

      const optionsTitle = document.createElement('p');
      optionsTitle.className = 'hear-me-scope-title';
      optionsTitle.setAttribute('data-hear-me-control', 'true');
      optionsTitle.textContent = Drupal.t('Optional areas:');
      controlsRoot.appendChild(optionsTitle);

      const optionsWrapper = document.createElement('div');
      optionsWrapper.className = 'hear-me-scope-options';
      optionsWrapper.setAttribute('data-hear-me-control', 'true');

      const commentsOption = createScopeOption('comments', Drupal.t('Include comments'));
      const sidebarOption = createScopeOption('sidebar', Drupal.t('Include sidebar'));
      const menuOption = createScopeOption('menu', Drupal.t('Include menu'));

      optionsWrapper.appendChild(commentsOption.wrapper);
      optionsWrapper.appendChild(sidebarOption.wrapper);
      optionsWrapper.appendChild(menuOption.wrapper);
      controlsRoot.appendChild(optionsWrapper);

      statusEl.insertAdjacentElement('afterend', controlsRoot);
    }

    return {
      root: controlsRoot,
      selectButton: controlsRoot.querySelector('[data-action="tts-select-section"]'),
      scopeInputs: {
        comments: controlsRoot.querySelector('[data-hear-me-scope="comments"]'),
        sidebar: controlsRoot.querySelector('[data-hear-me-scope="sidebar"]'),
        menu: controlsRoot.querySelector('[data-hear-me-scope="menu"]'),
      },
    };
  }

  function getScopeOptions(state) {
    return {
      includeComments: !!state.scopeInputs.comments?.checked,
      includeSidebar: !!state.scopeInputs.sidebar?.checked,
      includeMenu: !!state.scopeInputs.menu?.checked,
    };
  }

  function ensureBlockAudio(playButton, statusEl) {
    let audioEl = statusEl.previousElementSibling;
    while (audioEl && !audioEl.classList.contains('hear-me-block-audio')) {
      audioEl = audioEl.previousElementSibling;
    }

    if (!audioEl) {
      audioEl = document.createElement('audio');
      audioEl.className = 'hear-me-block-audio';
      audioEl.controls = true;
      audioEl.preload = 'none';
      audioEl.hidden = true;
      statusEl.insertAdjacentElement('beforebegin', audioEl);
    }

    if (audioEl.previousElementSibling !== playButton) {
      statusEl.insertAdjacentElement('beforebegin', audioEl);
    }

    audioEl.setAttribute('data-hear-me-control', 'true');
    return audioEl;
  }

  function setHoveredCandidate(state, candidate) {
    if (state.hoveredCandidate === candidate) {
      return;
    }

    if (state.hoveredCandidate) {
      state.hoveredCandidate.classList.remove('hear-me-select-hover');
    }

    state.hoveredCandidate = candidate;

    if (candidate) {
      candidate.classList.add('hear-me-select-hover');
    }
  }

  function flashSelectedCandidate(candidate) {
    candidate.classList.add('hear-me-select-selected');
    window.setTimeout(function () {
      candidate.classList.remove('hear-me-select-selected');
    }, 900);
  }

  function refreshSelectionTargets(state) {
    const scopeOptions = getScopeOptions(state);
    const primarySelectors = getPrimarySelectors(state.controlRoot);

    state.candidateSelectors = primarySelectors.slice();

    if (scopeOptions.includeComments) {
      state.candidateSelectors = state.candidateSelectors.concat(OPTIONAL_SCOPE_SELECTORS.comments);
    }

    if (scopeOptions.includeSidebar) {
      state.candidateSelectors = state.candidateSelectors.concat(OPTIONAL_SCOPE_SELECTORS.sidebar);
    }

    if (scopeOptions.includeMenu) {
      state.candidateSelectors = state.candidateSelectors.concat(OPTIONAL_SCOPE_SELECTORS.menu);
    }

    state.keyboardCandidates = collectSectionCandidates(
      state.candidateSelectors,
      state.controlRoot,
      scopeOptions,
    );
    state.candidateSet = new Set(state.keyboardCandidates);
  }

  function resolveCandidateFromElement(element, state) {
    if (!(element instanceof Element)) {
      return null;
    }

    const scopeOptions = getScopeOptions(state);

    if (element.closest('[data-hear-me-control="true"]') || state.controlRoot.contains(element)) {
      return null;
    }

    if (isInBlockedScope(element, scopeOptions)) {
      return null;
    }

    let current = element;
    while (current && current !== document.documentElement) {
      if (state.candidateSet?.has(current)) {
        return current;
      }
      current = current.parentElement;
    }

    return null;
  }

  function selectCandidateAndPlay(candidate, state) {
    if (!candidate) {
      showStatus(state.audioEl, Drupal.t('No selectable text was found in this section.'), 'warning');
      return;
    }

    const scopeOptions = getScopeOptions(state);
    const text = extractTextFromElement(candidate, scopeOptions);

    if (!text) {
      showStatus(state.audioEl, Drupal.t('No readable text was found in this section.'), 'warning');
      return;
    }

    flashSelectedCandidate(candidate);
    stopSelectionMode(state, { clearStatus: true });

    const lang = resolveLang(state.settings, null);
    fetchAndPlay(text, lang, state.audioEl);
  }

  function cycleSelectionCandidate(state, direction) {
    if (!state.keyboardCandidates.length) {
      refreshSelectionTargets(state);
    }

    if (!state.keyboardCandidates.length) {
      setHoveredCandidate(state, null);
      showStatus(state.audioEl, Drupal.t('No selectable sections are available with the current scope.'), 'warning');
      return;
    }

    const currentIndex = state.hoveredCandidate
      ? state.keyboardCandidates.indexOf(state.hoveredCandidate)
      : -1;

    let nextIndex = currentIndex + direction;
    if (nextIndex >= state.keyboardCandidates.length) {
      nextIndex = 0;
    }
    if (nextIndex < 0) {
      nextIndex = state.keyboardCandidates.length - 1;
    }

    const nextCandidate = state.keyboardCandidates[nextIndex];
    setHoveredCandidate(state, nextCandidate);

    if (nextCandidate?.scrollIntoView) {
      nextCandidate.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }
  }

  function stopSelectionMode(state, options) {
    if (!state.inspecting) {
      return;
    }

    document.removeEventListener('pointermove', state.onPointerMove, true);
    document.removeEventListener('click', state.onCaptureClick, true);
    document.removeEventListener('keydown', state.onKeyDown, true);

    if (state.pointerRaf) {
      window.cancelAnimationFrame(state.pointerRaf);
      state.pointerRaf = 0;
    }

    document.documentElement.classList.remove('hear-me-select-mode');

    state.inspecting = false;
    state.selectButton.classList.remove('is-active');
    state.selectButton.setAttribute('aria-pressed', 'false');
    state.selectButton.textContent = Drupal.t('Select section to listen');

    setHoveredCandidate(state, null);

    if (!options || options.clearStatus) {
      clearStatus(state.audioEl);
    }

    if (activeSelectionState === state) {
      activeSelectionState = null;
    }
  }

  function startSelectionMode(state) {
    if (activeSelectionState && activeSelectionState !== state) {
      stopSelectionMode(activeSelectionState, { clearStatus: true });
    }

    refreshSelectionTargets(state);

    if (!state.keyboardCandidates.length) {
      showStatus(state.audioEl, Drupal.t('No selectable sections are available on this page.'), 'warning');
      return;
    }

    activeSelectionState = state;
    state.inspecting = true;
    state.selectButton.classList.add('is-active');
    state.selectButton.setAttribute('aria-pressed', 'true');
    state.selectButton.textContent = Drupal.t('Cancel section selection');

    document.documentElement.classList.add('hear-me-select-mode');

    showStatus(
      state.audioEl,
      Drupal.t('Selection mode is on. Hover and click a section to listen, or use arrow keys and press Enter. Press Esc to cancel.'),
    );

    setHoveredCandidate(state, state.keyboardCandidates[0] || null);

    state.onPointerMove = function (event) {
      if (!state.inspecting || event.pointerType === 'touch') {
        return;
      }

      state.pointerX = event.clientX;
      state.pointerY = event.clientY;

      if (state.pointerRaf) {
        return;
      }

      state.pointerRaf = window.requestAnimationFrame(function () {
        state.pointerRaf = 0;

        const targets = document.elementsFromPoint(state.pointerX, state.pointerY);
        let candidate = null;

        for (const target of targets) {
          candidate = resolveCandidateFromElement(target, state);
          if (candidate) {
            break;
          }
        }

        setHoveredCandidate(state, candidate);
      });
    };

    state.onCaptureClick = function (event) {
      if (!state.inspecting) {
        return;
      }

      if (!(event.target instanceof Element)) {
        return;
      }

        if (event.target.closest('[data-hear-me-control="true"]')) {
          return;
        }

        event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation();

      const candidate = resolveCandidateFromElement(event.target, state) || state.hoveredCandidate;
      selectCandidateAndPlay(candidate, state);
    };

    state.onKeyDown = function (event) {
      if (!state.inspecting) {
        return;
      }

      if (event.key === 'Escape') {
        event.preventDefault();
        stopSelectionMode(state, { clearStatus: true });
        showStatus(state.audioEl, Drupal.t('Selection mode was cancelled.'), 'status');
        return;
      }

      if (event.target instanceof Element && event.target.closest('[data-hear-me-control="true"]')) {
        if (event.target === state.selectButton) {
          // Allow keyboard selection when focus remains on the toggle button.
        }
        else {
          return;
        }
      }

      if (event.target instanceof Element && event.target.matches('input, textarea, select, [contenteditable], [contenteditable="true"]')) {
        return;
      }

      if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
        event.preventDefault();
        cycleSelectionCandidate(state, 1);
        return;
      }

      if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
        event.preventDefault();
        cycleSelectionCandidate(state, -1);
        return;
      }

      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        selectCandidateAndPlay(state.hoveredCandidate, state);
      }
    };

    document.addEventListener('pointermove', state.onPointerMove, true);
    document.addEventListener('click', state.onCaptureClick, true);
    document.addEventListener('keydown', state.onKeyDown, true);
  }

  Drupal.behaviors.hearMeTts = {
    attach: function (context, settings) {

      once('hearMeTts', '.tts-play', context).forEach(function (el) {
        el.addEventListener('click', function () {
          const text = el.dataset.text;
          const lang = resolveLang(settings, el.dataset.lang);
          const audioEl = el.parentElement?.querySelector('.tts-audio');

          el.setAttribute('data-hear-me-control', 'true');
          audioEl?.setAttribute('data-hear-me-control', 'true');

          if (!audioEl) {
            showStatus(el, Drupal.t('Audio player is not available for this text.'), 'error');
            return;
          }

          fetchAndPlay(text, lang, audioEl);
        });
      });

      once('hearMeBlock', '[data-action="tts-page"]', context).forEach(function (el) {
        el.setAttribute('data-hear-me-control', 'true');
        el.type = 'button';

        const statusEl = getStatusElement(el);
        const audioEl = ensureBlockAudio(el, statusEl);

        const controls = ensureSelectionControls(statusEl);
        const state = {
          triggerButton: el,
          selectButton: controls.selectButton,
          scopeInputs: controls.scopeInputs,
          controlRoot: controls.root,
          audioEl: audioEl,
          hoveredCandidate: null,
          settings: settings,
          inspecting: false,
          candidateSelectors: [],
          keyboardCandidates: [],
          candidateSet: new Set(),
          pointerRaf: 0,
          pointerX: 0,
          pointerY: 0,
          onPointerMove: null,
          onCaptureClick: null,
          onKeyDown: null,
        };

        state.controlRoot.addEventListener('change', function () {
          if (state.inspecting) {
            refreshSelectionTargets(state);
            if (!state.keyboardCandidates.length) {
              setHoveredCandidate(state, null);
            }
          }
        });

        state.selectButton.addEventListener('click', function () {
          if (state.inspecting) {
            stopSelectionMode(state, { clearStatus: true });
            return;
          }

          startSelectionMode(state);
        });

        el.addEventListener('click', function () {
          if (activeSelectionState) {
            stopSelectionMode(activeSelectionState, { clearStatus: true });
          }

          const lang = resolveLang(settings, null);
          const selectedText = getSelectedText();

          if (selectedText) {
            fetchAndPlay(selectedText, lang, audioEl);
            return;
          }

          const text = collectDefaultPageText(
            state.controlRoot,
            getScopeOptions(state),
          );

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
