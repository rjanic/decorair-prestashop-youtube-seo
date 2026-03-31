(function () {

  function getTexts() {
    const fallback = {
      noResults: 'No results found',
      typeMore: 'Type at least 2 characters',
      searching: 'Searching...',
      startTyping: 'Start typing to search'
    };

    if (typeof decorairYtVideoAutocompleteTexts === 'undefined') {
      return fallback;
    }

    return Object.assign({}, fallback, decorairYtVideoAutocompleteTexts);
  }

  function setupAutocomplete(inputName, hiddenName, entityType) {
    const input = document.querySelector('[name="' + inputName + '"]');
    const hidden = document.querySelector('[name="' + hiddenName + '"]');
    const texts = getTexts();

    if (!input || !hidden) {
      return;
    }

    const wrapper = input.parentNode;
    if (!wrapper) {
      return;
    }

    wrapper.style.position = 'relative';

    const list = document.createElement('div');
    list.className = 'decorairytvideo-autocomplete';
    list.style.display = 'none';
    wrapper.appendChild(list);

    let timer = null;
    let controller = null;

    function clearList() {
      list.innerHTML = '';
      list.style.display = 'none';
    }

    function showMessage(message, extraClass) {
      list.innerHTML = '';
      const row = document.createElement('div');
      row.className = 'decorairytvideo-autocomplete-item decorairytvideo-autocomplete-message' + (extraClass ? ' ' + extraClass : '');
      row.textContent = message;
      list.appendChild(row);
      list.style.display = 'block';
    }

    function renderResults(results) {
      list.innerHTML = '';

      if (!results.length) {
        showMessage(texts.noResults, 'is-empty');
        return;
      }

      results.forEach(function (item) {
        const row = document.createElement('div');
        row.className = 'decorairytvideo-autocomplete-item';
        row.textContent = item.label;
        row.dataset.id = item.id;
        row.addEventListener('mousedown', function (event) {
          event.preventDefault();
          input.value = item.label;
          hidden.value = item.id;
          clearList();
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        list.appendChild(row);
      });

      list.style.display = 'block';
    }

    input.setAttribute('autocomplete', 'off');

    input.addEventListener('focus', function () {
      if (input.value.trim().length < 2) {
        showMessage(texts.typeMore, 'is-hint');
      }
    });

    input.addEventListener('input', function () {
      const q = input.value.trim();
      hidden.value = '';

      if (timer) {
        clearTimeout(timer);
      }

      if (controller) {
        controller.abort();
      }

      if (q.length < 2) {
        showMessage(texts.typeMore, 'is-hint');
        return;
      }

      showMessage(texts.searching, 'is-hint');

      timer = window.setTimeout(function () {
        controller = new AbortController();

        const requestUrl = new URL(window.location.href);
        requestUrl.hash = '';
        requestUrl.searchParams.set('ajax', '1');
        requestUrl.searchParams.set('action', 'searchDecorairYtVideoEntities');
        requestUrl.searchParams.set('entity_type', entityType);
        requestUrl.searchParams.set('q', q);
        requestUrl.searchParams.set('_ts', String(Date.now()));

        fetch(requestUrl.toString(), {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          signal: controller.signal,
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
          .then(function (response) {
            if (!response.ok) {
              throw new Error('HTTP ' + response.status);
            }
            return response.text();
          })
          .then(function (responseText) {
            let data = null;
            try {
              data = JSON.parse(responseText);
            } catch (parseError) {
              const jsonStart = responseText.indexOf('{');
              const jsonEnd = responseText.lastIndexOf('}');
              if (jsonStart !== -1 && jsonEnd > jsonStart) {
                data = JSON.parse(responseText.slice(jsonStart, jsonEnd + 1));
              } else {
                throw parseError;
              }
            }
            renderResults(Array.isArray(data.results) ? data.results : []);
          })
          .catch(function (error) {
            if (error.name === 'AbortError') {
              return;
            }
            showMessage('Ajax error', 'is-empty');
            console.error('[decorairytvideo] autocomplete error:', error);
          });
      }, 180);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        clearList();
      }
    });

    document.addEventListener('click', function (event) {
      if (!list.contains(event.target) && event.target !== input) {
        clearList();
      }
    });
  }

  function setupLanguageModeVisibility() {
    const modeSelect = document.querySelector('[name="language_mode"]');
    const singleLangSelect = document.querySelector('[name="single_lang_id"]');

    if (!modeSelect || !singleLangSelect) {
      return;
    }

    const singleLangGroup = singleLangSelect.closest('.form-group');
    if (!singleLangGroup) {
      return;
    }

    function refresh() {
      const isSingleMode = modeSelect.value === 'single';
      singleLangGroup.classList.toggle('decorairytvideo-hidden', !isSingleMode);
      singleLangSelect.disabled = !isSingleMode;
    }

    modeSelect.addEventListener('change', refresh);
    refresh();
  }

  function setupCopyTextHelper() {
    const helper = document.getElementById('decorairytvideo-copy-helper');
    if (!helper) {
      return;
    }

    const sourceSelect = helper.querySelector('#decorairytvideo-copy-source');
    const targetCheckboxes = helper.querySelectorAll('.decorairytvideo-copy-target');
    const actionButtons = helper.querySelectorAll('[data-copy-mode]');
    if (!sourceSelect || !targetCheckboxes.length || !actionButtons.length) {
      return;
    }
    let noticeEl = helper.querySelector('.decorairytvideo-copy-notice');
    if (!noticeEl) {
      noticeEl = document.createElement('div');
      noticeEl.className = 'decorairytvideo-copy-notice';
      helper.appendChild(noticeEl);
    }
    let noticeTimer = null;

    function showNotice(message) {
      noticeEl.textContent = message;
      noticeEl.classList.add('is-visible');
      if (noticeTimer) {
        clearTimeout(noticeTimer);
      }
      noticeTimer = window.setTimeout(function () {
        noticeEl.classList.remove('is-visible');
      }, 1800);
    }

    function getField(name, langId) {
      const fieldName = name + '_' + langId;
      return document.querySelector('[name="' + fieldName + '"]') || document.getElementById(fieldName);
    }

    function isEffectivelyEmpty(value) {
      return String(value || '')
        .replace(/\u00A0/g, ' ')
        .replace(/<[^>]*>/g, ' ')
        .trim() === '';
    }

    function copyField(baseName, sourceLangId, targetLangId, emptyOnly) {
      const sourceField = getField(baseName, sourceLangId);
      const targetField = getField(baseName, targetLangId);
      if (!sourceField || !targetField) {
        return false;
      }
      if (emptyOnly && !isEffectivelyEmpty(targetField.value)) {
        return false;
      }

      targetField.value = sourceField.value;
      targetField.dispatchEvent(new Event('input', { bubbles: true }));
      targetField.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }

    function getTargetIso(checkbox) {
      const label = checkbox.closest('.decorairytvideo-copy-target-item');
      const labelText = label ? label.textContent : '';
      const iso = String(labelText || '').split('-')[0].trim().toUpperCase();
      return iso || checkbox.value;
    }

    actionButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        const mode = button.getAttribute('data-copy-mode');
        const emptyOnly = button.getAttribute('data-copy-empty-only') === '1';
        const sourceLangId = sourceSelect.value;
        if (!sourceLangId) {
          button.blur();
          return;
        }

        const selectedTargets = Array.prototype.slice.call(targetCheckboxes).filter(function (checkbox) {
          return checkbox.checked;
        });

        if (!selectedTargets.length) {
          button.blur();
          showNotice('Najprv vyber cieľové jazyky');
          return;
        }

        const copiedIsoCodes = [];

        selectedTargets.forEach(function (checkbox) {
          let copiedForLang = false;

          const targetLangId = checkbox.value;
          if (!targetLangId) {
            return;
          }

          if (mode === 'title' || mode === 'both') {
            copiedForLang = copyField('title', sourceLangId, targetLangId, emptyOnly) || copiedForLang;
          }
          if (mode === 'description' || mode === 'both') {
            copiedForLang = copyField('description', sourceLangId, targetLangId, emptyOnly) || copiedForLang;
          }

          if (copiedForLang) {
            copiedIsoCodes.push(getTargetIso(checkbox));
          }
        });

        button.blur();
        if (copiedIsoCodes.length) {
          showNotice(
            (emptyOnly ? 'Boli vyplnené prázdne jazyky: ' : 'Boli vyplnené jazyky: ')
            + copiedIsoCodes.join(', ')
          );
        } else {
          showNotice(emptyOnly ? 'Neboli nájdené prázdne cieľové polia' : 'Neboli vyplnené žiadne jazyky');
        }
      });
    });
  }

  function setupYouTubeMetaFetcher() {
    const button = document.getElementById('decorairytvideo-fetch-meta');
    if (!button) {
      return;
    }

    const youtubeInput = document.querySelector('[name="youtube_id"]');
    const durationInput = document.querySelector('[name="duration"]');
    const uploadDateInput = document.querySelector('[name="upload_date"]');
    const thumbnailInput = document.querySelector('[name="thumbnail_url"]');
    const notice = document.getElementById('decorairytvideo-meta-notice');
    const previewWrap = document.querySelector('#decorairytvideo-meta-helper .decorairytvideo-meta-preview');
    const previewImg = document.getElementById('decorairytvideo-meta-thumb');
    const metaTitle = document.getElementById('decorairytvideo-meta-title');
    const metaDuration = document.getElementById('decorairytvideo-meta-duration');
    const metaUploadDate = document.getElementById('decorairytvideo-meta-upload-date');
    const metaFetchedAt = document.getElementById('decorairytvideo-meta-fetched-at');

    if (!youtubeInput || !durationInput || !thumbnailInput || !uploadDateInput) {
      return;
    }

    let noticeTimer = null;

    function showNotice(message, isError) {
      if (!notice) {
        return;
      }

      notice.textContent = message;
      notice.classList.remove('is-error');
      if (isError) {
        notice.classList.add('is-error');
      }
      notice.classList.add('is-visible');

      if (noticeTimer) {
        clearTimeout(noticeTimer);
      }
      noticeTimer = window.setTimeout(function () {
        notice.classList.remove('is-visible', 'is-error');
      }, 1900);
    }

    button.addEventListener('click', function () {
      const youtubeId = (youtubeInput.value || '').trim();
      if (!youtubeId) {
        button.blur();
        showNotice('YouTube ID is required', true);
        return;
      }

      const requestUrl = new URL(window.location.href);
      requestUrl.hash = '';
      requestUrl.searchParams.set('ajax', '1');
      requestUrl.searchParams.set('action', 'fetchDecorairYtVideoMeta');
      requestUrl.searchParams.set('youtube_input', youtubeId);
      requestUrl.searchParams.set('_ts', String(Date.now()));

      fetch(requestUrl.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }
          return response.json();
        })
        .then(function (data) {
          if (!data || data.success !== true) {
            showNotice((data && data.message) ? data.message : 'Metadata fetch failed', true);
            return;
          }

          if (typeof data.duration_iso === 'string' && data.duration_iso.length) {
            durationInput.value = data.duration_iso;
            durationInput.dispatchEvent(new Event('input', { bubbles: true }));
            durationInput.dispatchEvent(new Event('change', { bubbles: true }));
          }

          if (typeof data.upload_date === 'string') {
            uploadDateInput.value = data.upload_date;
            uploadDateInput.dispatchEvent(new Event('input', { bubbles: true }));
            uploadDateInput.dispatchEvent(new Event('change', { bubbles: true }));
          }

          if (typeof data.thumbnail_url === 'string') {
            thumbnailInput.value = data.thumbnail_url;
            thumbnailInput.dispatchEvent(new Event('input', { bubbles: true }));
            thumbnailInput.dispatchEvent(new Event('change', { bubbles: true }));

            if (previewWrap && previewImg && data.thumbnail_url.trim() !== '') {
              previewImg.src = data.thumbnail_url;
              previewWrap.style.display = '';
            }
          }

          if (metaTitle && typeof data.title === 'string') {
            metaTitle.textContent = data.title;
          }
          if (metaDuration) {
            metaDuration.textContent = (typeof data.duration_formatted === 'string' && data.duration_formatted)
              ? data.duration_formatted
              : (typeof data.duration_iso === 'string' ? data.duration_iso : '');
          }
          if (metaUploadDate && typeof data.upload_date === 'string') {
            metaUploadDate.textContent = data.upload_date;
          }
          if (metaFetchedAt && typeof data.fetched_at === 'string') {
            metaFetchedAt.textContent = data.fetched_at;
          }

          showNotice((data && data.message) ? data.message : 'Metadata fetched');
        })
        .catch(function (error) {
          console.error('[decorairytvideo] metadata fetch error:', error);
          showNotice('Metadata fetch failed', true);
        })
        .finally(function () {
          button.blur();
        });
    });
  }

  function setupApiKeyTester() {
    const button = document.getElementById('decorairytvideo-test-api-key');
    if (!button) {
      return;
    }

    const notice = document.getElementById('decorairytvideo-api-key-test-notice');
    let noticeTimer = null;

    function showNotice(message, isError) {
      if (!notice) {
        return;
      }

      notice.textContent = message;
      notice.classList.remove('is-error', 'is-success', 'is-visible');
      notice.classList.add(isError ? 'is-error' : 'is-success', 'is-visible');

      if (noticeTimer) {
        clearTimeout(noticeTimer);
      }
      noticeTimer = window.setTimeout(function () {
        notice.classList.remove('is-visible', 'is-error', 'is-success');
      }, 2600);
    }

    button.addEventListener('click', function () {
      const baseUrl = (typeof decorairYtVideoAjaxUrl !== 'undefined' && decorairYtVideoAjaxUrl)
        ? decorairYtVideoAjaxUrl
        : window.location.href;
      const requestUrl = new URL(baseUrl);
      requestUrl.hash = '';
      requestUrl.searchParams.set('ajax', '1');
      requestUrl.searchParams.set('action', 'testDecorairYtApiKey');
      requestUrl.searchParams.set('_ts', String(Date.now()));

      const originalText = button.textContent;
      button.disabled = true;
      button.classList.add('loading');
      button.textContent = 'Testing...';

      fetch(requestUrl.toString(), {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }
          return response.json();
        })
        .then(function (data) {
          if (!data || data.success !== true) {
            showNotice((data && data.message) ? data.message : 'API key test failed.', true);
            return;
          }

          showNotice((data && data.message) ? data.message : 'API key is valid.', false);
        })
        .catch(function (error) {
          console.error('[decorairytvideo] API key test error:', error);
          showNotice('Could not reach YouTube API.', true);
        })
        .finally(function () {
          button.disabled = false;
          button.classList.remove('loading');
          button.textContent = originalText;
          button.blur();
        });
    });
  }

  function initDecorairYtVideoAdmin() {
    setupAutocomplete('product_search_label', 'id_product', 'product');
    setupAutocomplete('category_search_label', 'id_category', 'category');
    setupLanguageModeVisibility();
    setupApiKeyTester();
    setupYouTubeMetaFetcher();
    try {
      setupCopyTextHelper();
    } catch (error) {
      console.error('[decorairytvideo] copy helper init error:', error);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDecorairYtVideoAdmin);
  } else {
    initDecorairYtVideoAdmin();
  }
})();
