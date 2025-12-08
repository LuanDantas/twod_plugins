(function (wp) {
  const { registerBlockType } = wp.blocks;
  const {
    PanelBody,
    ToggleControl,
    TextControl,
    Button,
    Notice,
    SelectControl,
  } = wp.components;
  const { InspectorControls, useBlockProps } = wp.blockEditor || wp.editor;
  const ServerSideRender = wp.serverSideRender;
  const apiFetch = wp.apiFetch;
  const { __ } = wp.i18n;

  const VARIANTS = [
    { label: __('Primário', 'akeno-interno'), value: 'primary' },
    { label: __('Secundário', 'akeno-interno'), value: 'secondary' },
    { label: __('Outline', 'akeno-interno'), value: 'outline' },
    { label: __('Inverso', 'akeno-interno'), value: 'inverse' },
    { label: __('CTA', 'akeno-interno'), value: 'cta' },
    { label: __('Pill', 'akeno-interno'), value: 'pill' },
    { label: __('Gradient', 'akeno-interno'), value: 'gradient' },
    { label: __('Shadow', 'akeno-interno'), value: 'shadow' },
    { label: __('Soft', 'akeno-interno'), value: 'soft' },
    { label: __('Flat', 'akeno-interno'), value: 'flat' },
    { label: __('Accent', 'akeno-interno'), value: 'accent' },
    { label: __('Horizontal', 'akeno-interno'), value: 'horizontal' },
    { label: __('Grande', 'akeno-interno'), value: 'large' },
  ];

  // Button templates for common use cases
  const BUTTON_TEMPLATES = [
    {
      name: __('Padrão', 'akeno-interno'),
      buttons: [{ text: '', dest: '', icon: '', src: false, srcValue: '' }],
    },
    {
      name: __('Navegação Dupla', 'akeno-interno'),
      buttons: [
        { text: '', dest: '', icon: '', src: false, srcValue: '' },
        { text: '', dest: '', icon: '', src: false, srcValue: '' },
      ],
    },
    {
      name: __('Navegação Tripla', 'akeno-interno'),
      buttons: [
        { text: '', dest: '', icon: '', src: false, srcValue: '' },
        { text: '', dest: '', icon: '', src: false, srcValue: '' },
        { text: '', dest: '', icon: '', src: false, srcValue: '' },
      ],
    },
  ];

  function pickAutoEmoji(text) {
    const rules = [
      [
        /wifi|wi[-\s]?fi|internet|rede|conex(ão|ao)|4g|5g|dados móveis|dados moveis/i,
        '📶',
      ],
      [
        /app|aplicativo|aplicación|aplicaciones|android|ios|play store|app store/i,
        '📱',
      ],
      [/radar|velocidade|lombada|multar/i, '📡'],
      [/carro|auto|veh(í|i)culo/i, '🚗'],
      [/moto|motocicleta/i, '🏍️'],
      [/placa|matr(í|i)cula|renavam/i, '🔎'],
      [/gr(á|a)tis|gratuito|free|sin costo|sem custo/i, '🆓'],
    ];
    for (const [re, emo] of rules) {
      if (re.test(text)) return emo;
    }
    return '';
  }

  // Validate URL format
  function isValidUrl(string) {
    try {
      if (/^\d+$/.test(string)) return true; // Post ID
      if (/^[a-z0-9\/\-_]+$/i.test(string)) return true; // Slug
      const url = new URL(string);
      return url.protocol === 'http:' || url.protocol === 'https:';
    } catch (_) {
      return false;
    }
  }

  async function ajax(url) {
    try {
      const data = await apiFetch({
        url,
        credentials: 'include',
        headers: {
          'X-WP-Nonce': AKENO_SETTINGS.nonce,
        },
      });
      return data && data.success ? data.data : null;
    } catch (e) {
      console.warn('Akeno ajax error', e);
      return null;
    }
  }

  const fetchPostInfo = async (id) => {
    if (!id || !/^\d+$/.test(String(id))) return null;
    return await ajax(
      `${AKENO_SETTINGS.ajaxUrl}?action=akeno_post_info&id=${id}&nonce=${AKENO_SETTINGS.nonce}`
    );
  };

  const fetchSuggestions = async (q) => {
    if (!q || q.trim() === '')
      return { items: [], provider: null, error: null };
    const result = await ajax(
      `${AKENO_SETTINGS.ajaxUrl}?action=akeno_suggest&q=${encodeURIComponent(
        q
      )}&nonce=${AKENO_SETTINGS.nonce}`
    );
    return result || { items: [], provider: null, error: null };
  };

  function registerAkeno(name, title) {
    registerBlockType(name, {
      apiVersion: 2,
      title,
      icon: 'admin-links',
      category: 'widgets',
      attributes: {
        includeSrcGlobal: { type: 'boolean', default: true },
        buttons: { type: 'array', default: [] },
        variant: { type: 'string', default: 'inverse' },
      },
      edit: (props) => {
        const { attributes, setAttributes, clientId } = props;
        const { includeSrcGlobal, buttons, variant } = attributes;
        const blockProps = useBlockProps();

        const selectBE = wp.data.select('core/block-editor');
        const getButtons = () => {
          try {
            const b = selectBE.getBlock(clientId)?.attributes?.buttons;
            return Array.isArray(b) ? b : buttons || [];
          } catch (e) {
            return buttons || [];
          }
        };
        const setButtons = (next) => setAttributes({ buttons: next });

        const [manualKeyword, setManualKeyword] = wp.element.useState('');
        const [suggestNotice, setSuggestNotice] = wp.element.useState(null);
        const [suggests, setSuggests] = wp.element.useState([]);
        const [isSuggesting, setIsSuggesting] = wp.element.useState(false);
        const [linkValidation, setLinkValidation] = wp.element.useState({});

        const addButton = () => {
          const cur = getButtons();
          if (cur.length >= 3) return;
          setButtons([
            ...cur,
            { text: '', dest: '', icon: '', src: false, srcValue: '' },
          ]);
        };

        const removeButton = (i) => {
          const cur = getButtons().slice();
          cur.splice(i, 1);
          setButtons(cur);
          // Clear validation for removed button
          const newValidation = { ...linkValidation };
          delete newValidation[i];
          setLinkValidation(newValidation);
        };

        const updateButton = (i, patch) => {
          const cur = getButtons().slice();
          cur[i] = { ...(cur[i] || {}), ...patch };
          setButtons(cur);
        };

        const applyTemplate = (template) => {
          if (template.buttons.length <= 3) {
            setButtons(template.buttons);
          }
        };

        // Optimized debounce with better error handling
        const debounce = (fn, ms) => {
          let t;
          return (...a) => {
            clearTimeout(t);
            t = setTimeout(() => fn(...a), ms);
          };
        };

        const validateLink = async (idx, dest) => {
          if (!dest || dest.trim() === '') {
            setLinkValidation({ ...linkValidation, [idx]: null });
            return;
          }

          // Basic format validation
          if (!isValidUrl(dest)) {
            setLinkValidation({
              ...linkValidation,
              [idx]: {
                valid: false,
                message: __('Formato inválido', 'akeno-interno'),
              },
            });
            return;
          }

          // If it's a post ID, check if it exists
          if (/^\d+$/.test(dest)) {
            const info = await fetchPostInfo(dest);
            if (info) {
              setLinkValidation({
                ...linkValidation,
                [idx]: {
                  valid: true,
                  message: __('Link válido', 'akeno-interno'),
                },
              });
            } else {
              setLinkValidation({
                ...linkValidation,
                [idx]: {
                  valid: false,
                  message: __('Post não encontrado', 'akeno-interno'),
                },
              });
            }
          } else {
            // For URLs and slugs, assume valid if format is correct
            setLinkValidation({
              ...linkValidation,
              [idx]: {
                valid: true,
                message: __('Formato válido', 'akeno-interno'),
              },
            });
          }
        };

        const resolveDest = debounce(async (idx, raw) => {
          if (!raw) {
            updateButton(idx, { _preview: '', _url: '' });
            await validateLink(idx, '');
            return;
          }

          // Validate link format
          await validateLink(idx, raw);

          if (/^\d+$/.test(raw)) {
            const info = await fetchPostInfo(raw);
            if (info) {
              updateButton(idx, {
                _preview: `${info.id} — ${info.title}`,
                _url: info.link,
                _valid: true,
              });
              const cur = getButtons();
              const b = cur[idx] || {};
              if ((!b.text || b.text === '') && (!b.icon || b.icon === '')) {
                const emo = pickAutoEmoji(info.title);
                if (emo) updateButton(idx, { icon: emo });
              }
            } else {
              updateButton(idx, {
                _preview: '',
                _url: '',
                _valid: false,
              });
            }
          } else {
            updateButton(idx, {
              _preview: '',
              _url: '',
              _valid: isValidUrl(raw),
            });
          }
        }, 300); // Increased debounce for better performance

        const onChangeDest = (idx, v) => {
          updateButton(idx, { dest: v });
          resolveDest(idx, v);
        };

        const onChangeText = (idx, v) => {
          const cur = getButtons();
          const b = cur[idx] || {};
          const patch = { text: v };
          if (!b.icon || b.icon === '') {
            const emo = pickAutoEmoji(v);
            if (emo) patch.icon = emo;
          }
          updateButton(idx, patch);
        };

        const suggestFromKeyword = async () => {
          setIsSuggesting(true);
          setSuggestNotice(null);
          let q = (manualKeyword || '').trim();
          if (!q) {
            let yoast = '';
            try {
              const s = wp.data.select('yoast-seo/editor');
              if (s && s.getFocusKeyphrase) yoast = s.getFocusKeyphrase();
            } catch (e) {}
            q =
              yoast ||
              wp.data
                .select('core/editor')
                ?.getEditedPostAttribute?.('title') ||
              'apps';
          }
          const res = await fetchSuggestions(q);
          const items = res && res.items ? res.items : [];
          setSuggests(items.slice(0, 10));
          setSuggestNotice({
            status: items.length ? 'success' : 'warning',
            text: items.length
              ? __('Sugestões carregadas', 'akeno-interno') +
                ` (${items.length})`
              : __('Sem sugestões online; base', 'akeno-interno') + ` "${q}"`,
          });
          setIsSuggesting(false);
        };

        return wp.element.createElement(
          'div',
          blockProps,
          wp.element.createElement(
            InspectorControls,
            {},
            wp.element.createElement(
              PanelBody,
              {
                title: __('Opções do bloco', 'akeno-interno'),
                initialOpen: true,
              },
              wp.element.createElement(ToggleControl, {
                label: __('Atribuir ROI', 'akeno-interno'),
                checked: includeSrcGlobal,
                onChange: (v) => setAttributes({ includeSrcGlobal: !!v }),
              }),
              wp.element.createElement(SelectControl, {
                label: __('Estilo dos botões', 'akeno-interno'),
                value: variant,
                options: VARIANTS,
                onChange: (v) => setAttributes({ variant: v }),
              })
            ),
            wp.element.createElement(
              PanelBody,
              { title: __('Templates', 'akeno-interno'), initialOpen: false },
              BUTTON_TEMPLATES.map((template, idx) =>
                wp.element.createElement(
                  Button,
                  {
                    key: idx,
                    variant: 'secondary',
                    onClick: () => applyTemplate(template),
                    style: { marginBottom: '8px', width: '100%' },
                  },
                  template.name
                )
              )
            ),
            wp.element.createElement(
              PanelBody,
              {
                title: __('Sugestões de Conteúdo', 'akeno-interno'),
                initialOpen: false,
              },
              wp.element.createElement(TextControl, {
                label: __('Palavra-chave (opcional)', 'akeno-interno'),
                value: manualKeyword,
                onChange: setManualKeyword,
                placeholder: __('Vazio = Yoast ou Título', 'akeno-interno'),
              }),
              wp.element.createElement(
                Button,
                {
                  variant: 'secondary',
                  onClick: suggestFromKeyword,
                  disabled: isSuggesting,
                },
                isSuggesting
                  ? __('Buscando…', 'akeno-interno')
                  : __('Sugerir com base na palavra-chave', 'akeno-interno')
              ),
              suggestNotice
                ? wp.element.createElement(
                    Notice,
                    {
                      status: suggestNotice.status,
                      isDismissible: true,
                      onRemove: () => setSuggestNotice(null),
                    },
                    suggestNotice.text
                  )
                : null,
              suggests &&
                suggests.length > 0 &&
                wp.element.createElement(
                  'div',
                  { className: 'akeno-suggest-list' },
                  suggests.map((s, i) =>
                    wp.element.createElement(
                      'div',
                      {
                        key: i,
                        className: 'akeno-suggest-item',
                        style: {
                          cursor: 'pointer',
                          padding: '8px',
                          border: '1px dashed #ccc',
                          marginBottom: '4px',
                          borderRadius: '4px',
                        },
                        onClick: () => {
                          setManualKeyword(s);
                          suggestFromKeyword();
                        },
                      },
                      s
                    )
                  )
                )
            ),
            wp.element.createElement(
              PanelBody,
              {
                title: __('Botões (máx. 3)', 'akeno-interno'),
                initialOpen: true,
              },
              getButtons().map((btn, idx) =>
                wp.element.createElement(
                  'div',
                  { key: idx, className: 'akeno-btn-config' },
                  wp.element.createElement(TextControl, {
                    label: __('TEXTO DO BOTÃO', 'akeno-interno'),
                    value: btn.text || '',
                    onChange: (v) => onChangeText(idx, v),
                  }),
                  wp.element.createElement(TextControl, {
                    label: __(
                      'Slug destino OU URL absoluta OU Post ID',
                      'akeno-interno'
                    ),
                    help: __(
                      'Ex.: 1709 (ID), "apps/placa" (slug) ou https://seusite.com/...',
                      'akeno-interno'
                    ),
                    value: btn.dest || '',
                    onChange: (v) => onChangeDest(idx, v),
                  }),
                  btn._preview
                    ? wp.element.createElement(
                        'div',
                        { className: 'akeno-previewline' },
                        btn._preview
                      )
                    : null,
                  btn._url
                    ? wp.element.createElement(
                        'div',
                        { className: 'akeno-previewurl' },
                        btn._url
                      )
                    : null,
                  linkValidation[idx] &&
                    wp.element.createElement(
                      Notice,
                      {
                        status: linkValidation[idx].valid ? 'success' : 'error',
                        isDismissible: false,
                      },
                      linkValidation[idx].message
                    ),
                  wp.element.createElement(TextControl, {
                    label: __('Ícone (emoji)', 'akeno-interno'),
                    value: btn.icon || '',
                    onChange: (v) => updateButton(idx, { icon: v }),
                  }),
                  wp.element.createElement(ToggleControl, {
                    label: __('Usar src individual', 'akeno-interno'),
                    checked: !!btn.src,
                    onChange: (v) => updateButton(idx, { src: !!v }),
                  }),
                  !!btn.src &&
                    wp.element.createElement(TextControl, {
                      label: __('Valor do src individual', 'akeno-interno'),
                      value: btn.srcValue || '',
                      onChange: (v) => updateButton(idx, { srcValue: v }),
                    }),
                  wp.element.createElement(
                    Button,
                    {
                      isDestructive: true,
                      onClick: () => removeButton(idx),
                    },
                    __('Remover botão', 'akeno-interno')
                  ),
                  wp.element.createElement('hr')
                )
              ),
              getButtons().length < 3 &&
                wp.element.createElement(
                  Button,
                  {
                    variant: 'primary',
                    onClick: addButton,
                  },
                  __('Adicionar botão', 'akeno-interno')
                )
            )
          ),
          wp.element.createElement(
            'div',
            { className: 'akeno-preview' },
            wp.element.createElement(ServerSideRender, {
              block: name,
              attributes,
            })
          )
        );
      },
      save: () => null,
    });
  }

  registerAkeno('twod/akeno-interno', __('Akeno Interno', 'akeno-interno'));
})(window.wp);
