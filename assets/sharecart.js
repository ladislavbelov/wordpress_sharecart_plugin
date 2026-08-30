(function () {
  'use strict';

  /**
   * closest() с простым фолбэком для кликов по вложенным элементам кнопок.
   */
  function sharecartClosest(el, selector) {
    if (!el) {
      return null;
    }

    if (el.closest) {
      return el.closest(selector);
    }

    while (el && el.nodeType === 1) {
      if (el.matches && el.matches(selector)) {
        return el;
      }
      el = el.parentElement;
    }

    return null;
  }

  /**
   * Ищем элемент сначала внутри текущей панели, затем во всём документе.
   */
  function sharecartFind(panel, selector) {
    if (panel) {
      var scoped = panel.querySelector(selector);
      if (scoped) {
        return scoped;
      }
    }

    return document.querySelector(selector);
  }

  /**
   * Показ сообщения внутри конкретной панели .sharecart-panel.
   * type: 'error' | 'success' | 'info'
   */
  function sharecartShowMessage(panel, type, text) {
    var msg = sharecartFind(panel, '#sharecart-message');
    if (!msg) {
      return;
    }

    msg.textContent = text;
    msg.style.display = 'inline-block';

    if (type === 'error') {
      msg.style.color = '#b32d2e';
    } else if (type === 'success') {
      msg.style.color = '#008b84';
    } else {
      msg.style.color = '#50575e';
    }
  }

  /**
   * Стартовое состояние панелей. Без привязки событий к конкретным DOM-узлам.
   */
  function sharecartPreparePanels() {
    var panels = document.querySelectorAll('.sharecart-panel');

    Array.prototype.forEach.call(panels, function (panel) {
      panel.style.display = 'block';

      if (panel.getAttribute('data-sharecart-prepared') === '1') {
        return;
      }

      var formBlock = panel.querySelector('#sharecart-form');
      var resultBlock = panel.querySelector('#sharecart-result');
      var messageEl = panel.querySelector('#sharecart-message');

      if (formBlock && !formBlock.style.display) {
        formBlock.style.display = 'none';
      }
      if (resultBlock && !resultBlock.style.display) {
        resultBlock.style.display = 'none';
      }
      if (messageEl) {
        messageEl.style.display = 'none';
      }

      panel.setAttribute('data-sharecart-prepared', '1');
    });
  }

  /**
   * Переключение видимости формы внутри панели.
   */
  function sharecartToggleForm(panel) {
    var form = sharecartFind(panel, '#sharecart-form');
    if (!form) {
      return;
    }

    var isVisible = form.style.display === 'block';
    form.style.display = isVisible ? 'none' : 'block';
  }

  /**
   * Обработчик генерации ссылки.
   */
  function sharecartHandleGenerate(panel) {
    var doctorInput = sharecartFind(panel, '#sharecart_doctor');
    var resultBlock = sharecartFind(panel, '#sharecart-result');
    var urlInput = sharecartFind(panel, '#sharecart-url');
    var doctor = doctorInput ? doctorInput.value.trim() : '';

    if (!doctor) {
      sharecartShowMessage(panel, 'error', 'Введите имя врача.');
      if (doctorInput) {
        doctorInput.focus();
      }
      return;
    }

    if (typeof ShareCartVars === 'undefined' ||
        !ShareCartVars ||
        !ShareCartVars.ajax_url ||
        !ShareCartVars.nonce) {
      sharecartShowMessage(
        panel,
        'error',
        'Ошибка конфигурации. Обновите страницу или обратитесь в поддержку.'
      );
      return;
    }

    sharecartShowMessage(panel, 'info', 'Создаём ссылку…');

    var formData = new FormData();
    formData.append('action', 'sharecart_generate_link');
    formData.append('nonce', ShareCartVars.nonce);
    formData.append('doctor_name', doctor);

    fetch(ShareCartVars.ajax_url, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (resp) {
        if (!resp || !resp.success || !resp.data || !resp.data.url) {
          var msg = (resp && resp.data && resp.data.message)
            ? resp.data.message
            : 'Не удалось создать ссылку.';

          sharecartShowMessage(panel, 'error', msg);
          return;
        }

        if (resultBlock) {
          resultBlock.style.display = 'block';
        }
        if (urlInput) {
          urlInput.value = resp.data.url;
          urlInput.focus();
          urlInput.select();
        }

        var couponsCount = resp.data.coupons_count || 0;
        var successText = couponsCount > 0
          ? 'Ссылка создана. Купон со скидкой тоже сохранён.'
          : 'Ссылка создана. Её можно скопировать и отправить.';

        sharecartShowMessage(panel, 'success', successText);
      })
      .catch(function (err) {
        if (window.console && console.error) {
          console.error('[ShareCart] generate_link fetch error:', err);
        }
        sharecartShowMessage(
          panel,
          'error',
          'Сетевая ошибка при создании ссылки. Попробуйте ещё раз.'
        );
      });
  }

  /**
   * Обработчик копирования ссылки.
   */
  function sharecartHandleCopy(panel) {
    var urlInput = sharecartFind(panel, '#sharecart-url');

    if (!urlInput || !urlInput.value) {
      sharecartShowMessage(panel, 'error', 'Нет ссылки для копирования.');
      return;
    }

    urlInput.focus();
    urlInput.select();

    var copied = false;
    try {
      copied = document.execCommand('copy');
    } catch (err) {
      if (window.console && console.error) {
        console.error('[ShareCart] execCommand copy error:', err);
      }
      copied = false;
    }

    if (!copied && navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(urlInput.value)
        .then(function () {
          sharecartShowMessage(panel, 'success', 'Ссылка скопирована в буфер обмена.');
        })
        .catch(function (err) {
          if (window.console && console.error) {
            console.error('[ShareCart] clipboard.writeText error:', err);
          }
          sharecartShowMessage(panel, 'error', 'Не удалось скопировать ссылку. Скопируйте её вручную.');
        });
      return;
    }

    if (copied) {
      sharecartShowMessage(panel, 'success', 'Ссылка скопирована в буфер обмена.');
    } else {
      sharecartShowMessage(panel, 'error', 'Не удалось скопировать ссылку. Скопируйте её вручную.');
    }
  }

  /**
   * Обработчик публичной кнопки "Добавить товары в корзину".
   */
  function sharecartHandlePublicAdd(target) {
    var key = target.getAttribute('data-cart-key');
    if (!key) {
      alert('Ключ не найден.');
      return;
    }

    if (!window.ShareCartVars ||
        !ShareCartVars.ajax_url ||
        !ShareCartVars.nonce) {
      alert('Конфигурация AJAX не найдена.');
      return;
    }

    var formData = new FormData();
    formData.append('action', 'sharecart_add_items');
    formData.append('nonce', ShareCartVars.nonce);
    formData.append('cart_key', key);

    fetch(ShareCartVars.ajax_url, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    })
      .then(function (r) {
        return r.json();
      })
      .then(function (resp) {
        if (!resp || !resp.success) {
          var msg = (resp && resp.data && resp.data.message)
            ? resp.data.message
            : 'Ошибка добавления товаров';
          alert(msg);
          if (window.console && console.warn) {
            console.warn('[ShareCart] add_items error:', resp);
          }
          return;
        }

        if (resp.data && resp.data.skipped && resp.data.skipped.length) {
          alert(
            'Добавлены с частичными пропусками (' +
            resp.data.skipped.length +
            '). Сейчас откроется корзина.'
          );
        }

        if (resp.data && resp.data.coupons_skipped && resp.data.coupons_skipped.length) {
          if (window.console && console.warn) {
            console.warn('[ShareCart] coupons skipped:', resp.data.coupons_skipped);
          }
        }

        var go = (resp.data && resp.data.redirect && resp.data.redirect.length)
          ? resp.data.redirect
          : (ShareCartVars.cart_url || '/cart/');
        window.location.href = go;
      })
      .catch(function (err) {
        if (window.console && console.error) {
          console.error('[ShareCart] add_items fetch error:', err);
        }
        alert('Сетевая ошибка при добавлении товаров.');
      });
  }

  /**
   * Один делегированный обработчик на документ.
   * Поэтому после WooCommerce AJAX-перерендера корзины события не слетают.
   */
  function sharecartBindDelegatedEvents() {
    if (window.__sharecartDelegatedEventsBound) {
      return;
    }

    window.__sharecartDelegatedEventsBound = true;

    document.addEventListener('click', function (e) {
      var target = e.target || e.srcElement;
      if (!target) {
        return;
      }

      var toggleBtn = sharecartClosest(target, '#sharecart-toggle');
      if (toggleBtn) {
        e.preventDefault();
        sharecartToggleForm(sharecartClosest(toggleBtn, '.sharecart-panel'));
        return;
      }

      var generateBtn = sharecartClosest(target, '#sharecart-generate');
      if (generateBtn) {
        e.preventDefault();
        sharecartHandleGenerate(sharecartClosest(generateBtn, '.sharecart-panel'));
        return;
      }

      var copyBtn = sharecartClosest(target, '#sharecart-copy');
      if (copyBtn) {
        e.preventDefault();
        sharecartHandleCopy(sharecartClosest(copyBtn, '.sharecart-panel'));
        return;
      }

      var addBtn = sharecartClosest(target, '#sharecart-add');
      if (addBtn) {
        e.preventDefault();
        sharecartHandlePublicAdd(addBtn);
      }
    });
  }

  /**
   * Общая инициализация.
   */
  function sharecartInit() {
    sharecartPreparePanels();
    sharecartBindDelegatedEvents();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', sharecartInit);
  } else {
    sharecartInit();
  }

  /**
   * WooCommerce после применения купона/обновления корзины часто перерисовывает cart form.
   * Делегированные клики уже работают, а это только приводит новую панель к стартовому состоянию.
   */
  if (window.jQuery) {
    window.jQuery(document.body).on('updated_wc_div wc_fragments_refreshed', sharecartPreparePanels);
  }
})();
