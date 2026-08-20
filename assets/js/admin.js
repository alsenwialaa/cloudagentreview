(() => {
  'use strict';

  const dangerForm = document.querySelector('[data-ysai-danger]');
  dangerForm?.addEventListener('submit', (event) => {
    const value = String(new FormData(dangerForm).get('confirmation') || '').trim().toUpperCase();
    if (value !== 'DELETE' || !window.confirm('سيتم حذف جميع محادثات الإصدار الجديد نهائيًا. متابعة؟')) {
      event.preventDefault();
    }
  });

  const workbench = document.querySelector('[data-ysai-appearance]');
  const preview = workbench?.querySelector('[data-ysai-preview]');
  const stage = workbench?.querySelector('[data-ysai-preview-stage]');
  if (!workbench || !preview || !stage) return;

  const config = window.YSAIAdminAppearance || {};
  const presets = config.presets && typeof config.presets === 'object' ? config.presets : {};
  const fields = new Map();
  const colorMap = {
    widget_brand_color: '--ysai-primary',
    widget_brand_strong_color: '--ysai-primary-strong',
    widget_surface_color: '--ysai-panel',
    widget_canvas_color: '--ysai-canvas',
    widget_assistant_bubble_color: '--ysai-assistant',
    widget_user_bubble_color: '--ysai-user',
    widget_receipt_bubble_color: '--ysai-receipt',
    widget_border_color: '--ysai-line',
  };
  const paletteMap = {
    widget_brand_color: 'brand',
    widget_brand_strong_color: 'brand_strong',
    widget_surface_color: 'surface',
    widget_canvas_color: 'canvas',
    widget_assistant_bubble_color: 'assistant',
    widget_user_bubble_color: 'user',
    widget_receipt_bubble_color: 'receipt',
    widget_border_color: 'border',
  };
  let activePreset = '';

  const keyFromName = (name) => {
    const match = String(name || '').match(/^ysai_options\[([a-z0-9_]+)]$/i);
    return match ? match[1] : '';
  };

  workbench.querySelectorAll('[name^="ysai_options["]').forEach((field) => {
    const key = keyFromName(field.name);
    if (key) fields.set(key, field);
  });

  const field = (key) => fields.get(key) || null;
  const value = (key, fallback = '') => String(field(key)?.value ?? fallback);
  const checked = (key) => Boolean(field(key)?.checked);
  const text = (selector, content) => {
    const node = preview.querySelector(selector);
    if (node) node.textContent = String(content || '');
  };
  const visible = (selector, show) => {
    const node = preview.querySelector(selector);
    if (node) node.hidden = !show;
  };

  const hexToRgb = (hex) => {
    const match = String(hex).match(/^#([0-9a-f]{6})$/i);
    if (!match) return [255, 255, 255];
    return [0, 2, 4].map((offset) => Number.parseInt(match[1].slice(offset, offset + 2), 16));
  };

  const luminance = (hex) => hexToRgb(hex).reduce((sum, channel, index) => {
    const normalized = channel / 255;
    const linear = normalized <= 0.04045
      ? normalized / 12.92
      : ((normalized + 0.055) / 1.055) ** 2.4;
    return sum + linear * [0.2126, 0.7152, 0.0722][index];
  }, 0);

  const readable = (background, dark = '#172033', light = '#ffffff') => {
    const bg = luminance(background);
    const contrast = (candidate) => {
      const fg = luminance(candidate);
      return (Math.max(bg, fg) + 0.05) / (Math.min(bg, fg) + 0.05);
    };
    return contrast(dark) >= contrast(light) ? dark : light;
  };

  const updateRangeOutput = (input) => {
    const output = input.closest('.ysai-range-control')?.querySelector('[data-ysai-range-output]');
    if (!output) return;
    output.textContent = `${input.value}${output.dataset.unit || ''}`;
  };

  const updateColorCode = (input) => {
    const code = input.closest('.ysai-color-control')?.querySelector('[data-ysai-color-value]');
    if (code) code.textContent = input.value;
  };

  const updateAvatar = () => {
    const mode = value('widget_avatar_style', 'site_icon');
    preview.dataset.avatar = mode;
    preview.querySelectorAll('[data-preview-avatar], .ysai-admin-preview__launcher .ysai-admin-preview__avatar').forEach((node) => {
      const image = node.querySelector('img');
      let marker = node.querySelector('b');
      if (mode === 'site_icon' && image) {
        image.hidden = false;
        marker?.remove();
        return;
      }
      if (image) image.hidden = true;
      if (!marker) {
        marker = document.createElement('b');
        node.prepend(marker);
      }
      marker.textContent = mode === 'chat' ? '◌' : 'ي';
    });
  };

  const updatePreview = () => {
    const presetName = value('widget_theme_preset', 'yassin');
    const preset = presets[presetName] || {};
    preview.dataset.theme = presetName;
    preview.dataset.layout = value('widget_product_layout', 'carousel');
    preview.dataset.density = value('widget_message_density', 'comfortable');
    preview.dataset.launcher = value('widget_launcher_style', 'pill');
    preview.dataset.position = value('widget_position', 'right');
    preview.dataset.mobileLabel = checked('widget_launcher_show_label_mobile') ? 'show' : 'hide';
    preview.classList.toggle('is-disabled', !checked('widget_enabled'));
    visible('[data-preview-disabled]', !checked('widget_enabled'));

    for (const [key, token] of Object.entries(colorMap)) {
      const color = value(key, '#ffffff');
      preview.style.setProperty(token, color);
    }
    if (preset.accent) preview.style.setProperty('--ysai-accent', preset.accent);

    const surface = value('widget_surface_color', '#ffffff');
    const assistant = value('widget_assistant_bubble_color', '#ffffff');
    const user = value('widget_user_bubble_color', '#7f1d1d');
    const brand = value('widget_brand_color', '#7f1d1d');
    preview.style.setProperty('--ysai-ink', readable(surface));
    preview.style.setProperty('--ysai-muted', readable(surface, '#667085', '#d0d5dd'));
    preview.style.setProperty('--ysai-assistant-text', readable(assistant));
    preview.style.setProperty('--ysai-user-text', readable(user));
    preview.style.setProperty('--ysai-on-primary', readable(brand));

    text('[data-preview-button]', value('widget_button_text', 'مساعدة ياسين'));
    text('[data-preview-title]', value('widget_title', 'مساعدة متجر ياسين'));
    text('[data-preview-subtitle]', value('widget_subtitle', 'اسأل عن المنتجات والأسعار والسلة'));
    text('[data-preview-welcome]', value('widget_welcome_message', 'مرحبًا! كيف أقدر أساعدك؟'));
    text('[data-preview-quick-1]', value('widget_quick_prompt_1', 'رشّح لي منتجات مناسبة'));
    text('[data-preview-quick-2]', value('widget_quick_prompt_2', 'قارن بين الخيارات'));
    text('[data-preview-quick-3]', value('widget_quick_prompt_3', 'ما سياسات الشحن والاسترجاع؟'));

    const presence = checked('widget_show_presence');
    visible('[data-preview-presence]', presence);
    preview.querySelectorAll('.ysai-admin-preview__avatar i').forEach((node) => {
      node.hidden = !presence;
    });
    preview.querySelectorAll('time, .ysai-admin-preview__day').forEach((node) => {
      node.hidden = !checked('widget_show_timestamps');
    });
    visible('[data-preview-actions]', checked('widget_show_message_actions'));
    visible('[data-preview-quick]', checked('widget_quick_replies_enabled'));
    visible('[data-preview-unread]', checked('widget_show_unread_button'));
    visible('[data-preview-latest]', checked('widget_show_unread_button'));
    visible('[data-preview-cart]', checked('widget_cart_summary_enabled'));
    visible('[data-preview-privacy]', checked('widget_conversation_privacy_enabled'));
    visible('[data-preview-product-nav]', checked('widget_product_carousel_indicator_enabled'));
    preview.querySelectorAll('[data-preview-product-description]').forEach((node) => {
      node.hidden = !checked('widget_product_show_description');
    });

    const maximumCards = Math.max(1, Math.min(12, Number.parseInt(value('max_display_cards', '6'), 10) || 6));
    preview.querySelectorAll('[data-preview-product]').forEach((node, index) => {
      node.hidden = index >= Math.min(3, maximumCards);
    });
    visible('[data-preview-products]', maximumCards > 0);

    const device = String(stage.dataset.device || 'desktop');
    const cardsKey = device === 'desktop'
      ? 'widget_product_cards_per_view_desktop'
      : 'widget_product_cards_per_view_mobile';
    const cardsMaximum = device === 'desktop' ? 3 : 2;
    const cardsPerView = Math.max(1, Math.min(
      cardsMaximum,
      Number.parseInt(value(cardsKey, device === 'desktop' ? '2' : '1'), 10) || 1,
    ));
    preview.style.setProperty('--ysai-preview-cards', String(cardsPerView));
    const ratio = {
      '1-1': '1 / 1',
      '4-3': '4 / 3',
      '3-4': '3 / 4',
      '16-9': '16 / 9',
    }[value('widget_product_image_ratio', '1-1')] || '1 / 1';
    preview.style.setProperty('--ysai-preview-product-ratio', ratio);
    preview.style.setProperty(
      '--ysai-preview-product-name-size',
      `${Math.max(8, (Number.parseInt(value('widget_product_name_font_size', '15'), 10) || 15) * 0.65)}px`,
    );
    preview.style.setProperty(
      '--ysai-preview-product-name-weight',
      String(Math.max(400, Math.min(900, Number.parseInt(value('widget_product_name_font_weight', '700'), 10) || 700))),
    );
    preview.style.setProperty(
      '--ysai-preview-product-name-lines',
      String(Math.max(1, Math.min(4, Number.parseInt(value('widget_product_name_max_lines', '2'), 10) || 2))),
    );
    updateAvatar();

    const panel = preview.querySelector('[data-preview-panel]');
    if (panel) {
      const width = Number.parseInt(value('widget_panel_width', '420'), 10) || 420;
      const height = Number.parseInt(value('widget_panel_height', '700'), 10) || 700;
      panel.style.width = `min(100%, ${Math.max(340, Math.min(560, width))}px)`;
      panel.style.height = `${Math.max(520, Math.min(660, Math.round(height * 0.94)))}px`;
      panel.style.borderRadius = `${value('widget_panel_radius', '24')}px`;
      panel.style.fontSize = `${Math.max(10, (Number.parseInt(value('widget_font_size', '15'), 10) || 15) * 0.8)}px`;
    }
    preview.querySelectorAll('.ysai-admin-preview__bubble').forEach((bubble) => {
      bubble.style.borderRadius = `${value('widget_bubble_radius', '18')}px`;
    });
    const product = preview.querySelector('[data-preview-product]');
    if (product) product.style.borderRadius = `${value('widget_product_card_radius', '16')}px`;
  };

  for (const input of fields.values()) {
    const eventName = input.matches('select, input[type="checkbox"], input[type="radio"]') ? 'change' : 'input';
    input.addEventListener(eventName, () => {
      if (input.matches('[data-ysai-range]')) updateRangeOutput(input);
      if (input.matches('[data-ysai-color]')) updateColorCode(input);
      updatePreview();
    });
    if (input.matches('[data-ysai-range]')) updateRangeOutput(input);
    if (input.matches('[data-ysai-color]')) updateColorCode(input);
  }

  field('widget_theme_preset')?.addEventListener('change', (event) => {
    const nextName = String(event.target.value || '');
    const preset = presets[nextName];
    if (!preset) return;
    const previous = presets[activePreset] || {};
    for (const [key, paletteKey] of Object.entries(paletteMap)) {
      const input = field(key);
      if (!input || typeof preset[paletteKey] !== 'string') continue;
      const previousColor = typeof previous[paletteKey] === 'string'
        ? previous[paletteKey].toLowerCase()
        : '';
      // A preset switch follows only colours that still inherit the previous
      // preset. Deliberate merchant overrides remain intact.
      if (previousColor !== '' && String(input.value || '').toLowerCase() !== previousColor) continue;
      input.value = preset[paletteKey];
      updateColorCode(input);
    }
    activePreset = nextName;
    updatePreview();
  });

  workbench.querySelectorAll('[data-ysai-preview-device]').forEach((button) => {
    button.addEventListener('click', () => {
      workbench.querySelectorAll('[data-ysai-preview-device]').forEach((candidate) => {
        const active = candidate === button;
        candidate.classList.toggle('is-active', active);
        candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      stage.dataset.device = button.dataset.ysaiPreviewDevice || 'desktop';
      updatePreview();
    });
  });

  activePreset = value('widget_theme_preset', 'yassin');
  updatePreview();
})();
