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
    '.node__title',
    '.teaser__title',
    '.node__content',
    '.teaser__content',
    '.field--name-body .field__item',
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

  const PAGE_TITLE_SELECTORS = [
    'main h1.page-title',
    'main .page-title',
    'main h1',
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
  let floatingUiId = 0;

  const FLOATING_UI_STORAGE_PREFIX = 'Drupal.hearMe.floatingUi.';

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

  function getFloatingUiStorageKey(settings) {
    const userId = settings.user?.uid || drupalSettings.user?.uid || '0';
    return FLOATING_UI_STORAGE_PREFIX + userId;
  }

  function loadFloatingUiState(settings) {
    const state = {
      collapsed: false,
      dock: 'right',
    };

    try {
      const storedState = window.localStorage.getItem(getFloatingUiStorageKey(settings));
      if (!storedState) {
        return state;
      }

      const parsedState = JSON.parse(storedState);
      state.collapsed = parsedState.collapsed === true;

      if (parsedState.dock === 'left' || parsedState.dock === 'right') {
        state.dock = parsedState.dock;
      }
    }
    catch (e) {
      return state;
    }

    return state;
  }

  function saveFloatingUiState(settings, state) {
    try {
      window.localStorage.setItem(getFloatingUiStorageKey(settings), JSON.stringify({
        collapsed: state.collapsed === true,
        dock: state.dock === 'left' ? 'left' : 'right',
      }));
    }
    catch (e) {}
  }

  function findDirectChildByClass(parent, className) {
    return Array.from(parent.children).find(function (child) {
      return child.classList.contains(className);
    }) || null;
  }

  function createPanelActionButton(className, action, labelText) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.setAttribute('data-action', action);
    button.setAttribute('data-hear-me-control', 'true');
    button.textContent = labelText;

    return button;
  }

  function applyFloatingUiState(ui, state) {
    const collapsed = state.collapsed === true;
    const dock = state.dock === 'left' ? 'left' : 'right';

    ui.blockRoot.classList.toggle('is-hear-me-collapsed', collapsed);
    ui.blockRoot.classList.toggle('hear-me-dock-left', dock === 'left');
    ui.blockRoot.classList.toggle('hear-me-dock-right', dock === 'right');
    ui.body.hidden = collapsed;
    ui.dockButton.hidden = collapsed;

    ui.collapseButton.classList.toggle('is-fab', collapsed);
    ui.collapseButton.setAttribute('aria-controls', ui.body.id);
    ui.collapseButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    ui.collapseButton.setAttribute(
      'aria-label',
      collapsed ? Drupal.t('Expand HearMe controls') : Drupal.t('Minimize HearMe controls'),
    );
    ui.collapseButton.textContent = collapsed ? '🔊' : Drupal.t('Minimize');

    ui.dockButton.textContent = dock === 'right' ? Drupal.t('Dock left') : Drupal.t('Dock right');
    ui.dockButton.setAttribute(
      'aria-label',
      dock === 'right' ? Drupal.t('Move HearMe controls to the left') : Drupal.t('Move HearMe controls to the right'),
    );
  }

  function ensureFloatingUi(playButton, settings) {
    const contentRoot = playButton.closest('.block__content') || playButton.parentElement;
    const blockRoot = playButton.closest('.block-hear-me-block') || playButton.closest('.block-hear-me') || contentRoot;
    let header = findDirectChildByClass(contentRoot, 'hear-me-floating-header');
    let body = findDirectChildByClass(contentRoot, 'hear-me-panel-body');

    blockRoot.classList.add('hear-me-floating-block');
    blockRoot.setAttribute('data-hear-me-control', 'true');

    if (!header || !body) {
      const existingChildren = Array.from(contentRoot.childNodes);

      header = document.createElement('div');
      header.className = 'hear-me-floating-header';
      header.setAttribute('data-hear-me-control', 'true');

      body = document.createElement('div');
      body.className = 'hear-me-panel-body';
      body.id = 'hear-me-panel-body-' + (++floatingUiId);
      body.setAttribute('data-hear-me-control', 'true');

      contentRoot.appendChild(header);
      contentRoot.appendChild(body);
      existingChildren.forEach(function (child) {
        body.appendChild(child);
      });
    }

    if (!body.id) {
      body.id = 'hear-me-panel-body-' + (++floatingUiId);
    }

    let dockButton = header.querySelector('[data-action="hear-me-dock"]');
    if (!dockButton) {
      dockButton = createPanelActionButton(
        'hear-me-panel-action hear-me-dock-toggle',
        'hear-me-dock',
        Drupal.t('Dock left'),
      );
      header.appendChild(dockButton);
    }

    let collapseButton = header.querySelector('[data-action="hear-me-collapse"]');
    if (!collapseButton) {
      collapseButton = createPanelActionButton(
        'hear-me-panel-action hear-me-collapse-toggle',
        'hear-me-collapse',
        Drupal.t('Minimize'),
      );
      header.appendChild(collapseButton);
    }

    const ui = {
      blockRoot: blockRoot,
      body: body,
      dockButton: dockButton,
      collapseButton: collapseButton,
      state: loadFloatingUiState(settings),
    };

    applyFloatingUiState(ui, ui.state);
    blockRoot.classList.add('hear-me-floating-ready');

    return ui;
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

  function isRedundantBodyDescendant(element) {
    if (!element || !(element instanceof Element)) {
      return false;
    }

    return !!element.closest('.teaser__content, .node__content, .field--name-body .field__item');
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

          if (element.matches('p, li') && isRedundantBodyDescendant(element)) {
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

  function collectPageTitleCandidates(controlRoot, scopeOptions) {
    return collectElementsBySelectors(
      PAGE_TITLE_SELECTORS,
      controlRoot,
      scopeOptions,
      { allowNested: true },
    );
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
      selectButton.className = 'hear-me-select-toggle hear-me-control-button';
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

    collectPageTitleCandidates(state.controlRoot, scopeOptions).reverse().forEach(function (titleCandidate) {
      if (!state.keyboardCandidates.includes(titleCandidate)) {
        state.keyboardCandidates.unshift(titleCandidate);
      }
    });

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

        const floatingUi = ensureFloatingUi(el, settings);
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
          floatingUi: floatingUi,
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

        floatingUi.collapseButton.addEventListener('click', function () {
          if (activeSelectionState) {
            stopSelectionMode(activeSelectionState, { clearStatus: true });
          }

          floatingUi.state.collapsed = !floatingUi.state.collapsed;
          applyFloatingUiState(floatingUi, floatingUi.state);
          saveFloatingUiState(settings, floatingUi.state);
        });

        floatingUi.dockButton.addEventListener('click', function () {
          floatingUi.state.dock = floatingUi.state.dock === 'right' ? 'left' : 'right';
          applyFloatingUiState(floatingUi, floatingUi.state);
          saveFloatingUiState(settings, floatingUi.state);
        });

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
