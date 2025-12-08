(function(wp){
  const { registerBlockType } = wp.blocks;
  const { PanelBody, ToggleControl, TextControl, Button, Notice, SelectControl } = wp.components;
  const { InspectorControls, useBlockProps } = wp.blockEditor || wp.editor;
  const ServerSideRender = wp.serverSideRender;
  const apiFetch = wp.apiFetch;

  const VARIANTS = [
    { label: 'Primário', value: 'primary' },
    { label: 'Secundário', value: 'secondary' },
    { label: 'Outline', value: 'outline' },
    { label: 'Inverso', value: 'inverse' },
    { label: 'CTA', value: 'cta' },
    { label: 'Pill', value: 'pill' },
    { label: 'Gradient', value: 'gradient' },
    { label: 'Shadow', value: 'shadow' },
    { label: 'Soft', value: 'soft' },
    { label: 'Flat', value: 'flat' },
    { label: 'Accent', value: 'accent' },
    { label: 'Horizontal', value: 'horizontal' },
    { label: 'Grande', value: 'large' },
  ];

  function pickAutoEmoji(text){
    const rules = [
      [/wifi|wi[-\s]?fi|internet|rede|conex(ão|ao)|4g|5g|dados móveis|dados moveis/i, "📶"],
      [/app|aplicativo|aplicación|aplicaciones|android|ios|play store|app store/i, "📱"],
      [/radar|velocidade|lombada|multar/i, "📡"],
      [/carro|auto|veh(í|i)culo/i, "🚗"],
      [/moto|motocicleta/i, "🏍️"],
      [/placa|matr(í|i)cula|renavam/i, "🔎"],
      [/gr(á|a)tis|gratuito|free|sin costo|sem custo/i, "🆓"]
    ];
    for (const [re, emo] of rules){ if (re.test(text)) return emo; }
    return "";
  }

  async function ajax(url){
    try{ const data = await apiFetch({ url, credentials: 'include' }); return data && data.success ? data.data : null; }
    catch(e){ console.warn('Akeno ajax error', e); return null; }
  }
  const fetchPostInfo = async(id)=> await ajax(`${AKENO_SETTINGS.ajaxUrl}?action=akeno_post_info&id=${id}&nonce=${AKENO_SETTINGS.nonce}`);
  const fetchSuggestions = async(q)=> (await ajax(`${AKENO_SETTINGS.ajaxUrl}?action=akeno_suggest&q=${encodeURIComponent(q)}&nonce=${AKENO_SETTINGS.nonce}`)) || {items:[], provider:null, error:null};

  function registerAkeno(name, title){
    registerBlockType(name, {
      apiVersion: 2,
      title, icon: 'admin-links', category: 'widgets',
      attributes: {
        includeSrcGlobal: { type: 'boolean', default: true },
        buttons: { type: 'array', default: [] },
        variant: { type: 'string', default: 'inverse' },
      },
      edit: (props)=>{
        const { attributes, setAttributes, clientId } = props;
        const { includeSrcGlobal, buttons, variant } = attributes;
        const blockProps = useBlockProps();

        const selectBE = wp.data.select('core/block-editor');
        const getButtons = ()=>{
          try{ const b = selectBE.getBlock(clientId)?.attributes?.buttons; return Array.isArray(b)? b: (buttons||[]); }
          catch(e){ return buttons||[]; }
        };
        const setButtons = (next)=> setAttributes({ buttons: next });

        const [manualKeyword, setManualKeyword] = wp.element.useState('');
        const [suggestNotice, setSuggestNotice] = wp.element.useState(null);
        const [suggests, setSuggests] = wp.element.useState([]);
        const [isSuggesting, setIsSuggesting] = wp.element.useState(false);

        const addButton = ()=>{
          const cur = getButtons();
          if (cur.length >= 3) return;
          setButtons([ ...cur, { text:'', dest:'', icon:'', src:false, srcValue:'' } ]);
        };
        const removeButton = (i)=>{
          const cur = getButtons().slice(); cur.splice(i,1); setButtons(cur);
        };
        const updateButton = (i, patch)=>{
          const cur = getButtons().slice(); cur[i] = { ...(cur[i]||{}), ...patch }; setButtons(cur);
        };

        const debounce = (fn, ms)=>{ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; };
        const resolveDest = debounce(async (idx, raw)=>{
          if (!raw){ updateButton(idx, { _preview:'', _url:'' }); return; }
          if (/^\d+$/.test(raw)) {
            const info = await fetchPostInfo(raw);
            if (info){
              updateButton(idx, { _preview:`${info.id} — ${info.title}`, _url: info.link });
              const cur = getButtons(); const b = cur[idx] || {};
              if ((!b.text||b.text==='') && (!b.icon||b.icon==='')){
                const emo = pickAutoEmoji(info.title); if (emo) updateButton(idx, { icon: emo });
              }
            }
          } else {
            updateButton(idx, { _preview:'', _url:'' });
          }
        }, 220);

        const onChangeDest = (idx, v)=>{ updateButton(idx, { dest: v }); resolveDest(idx, v); };
        const onChangeText = (idx, v)=>{
          const cur = getButtons(); const b = cur[idx] || {}; const patch = { text: v };
          if ((!b.icon||b.icon==='')){ const emo = pickAutoEmoji(v); if (emo) patch.icon = emo; }
          updateButton(idx, patch);
        };

        const suggestFromKeyword = async ()=>{
          setIsSuggesting(true); setSuggestNotice(null);
          let q = (manualKeyword||'').trim();
          if (!q){
            let yoast = ''; try{ const s=wp.data.select('yoast-seo/editor'); if (s && s.getFocusKeyphrase) yoast = s.getFocusKeyphrase(); }catch(e){}
            q = yoast || (wp.data.select('core/editor')?.getEditedPostAttribute?.('title') || 'apps');
          }
          const res = await fetchSuggestions(q);
          const items = (res && res.items) ? res.items : [];
          setSuggests(items.slice(0,10));
          setSuggestNotice({ status: items.length? 'success':'warning', text: items.length? `Sugestões carregadas (${items.length})` : `Sem sugestões online; base "${q}"` });
          setIsSuggesting(false);
        };

        return wp.element.createElement('div', blockProps,
          wp.element.createElement(InspectorControls, {},
            wp.element.createElement(PanelBody, { title: 'Opções do bloco', initialOpen: true },
              wp.element.createElement(ToggleControl, { label:'Atribuir ROI', checked: includeSrcGlobal, onChange:(v)=> setAttributes({ includeSrcGlobal: !!v }) }),
              wp.element.createElement(SelectControl, { label:'Estilo dos botões', value: variant, options: VARIANTS, onChange:(v)=> setAttributes({ variant: v }) }),
            ),
            wp.element.createElement(PanelBody, { title: 'Sugestões de Conteúdo', initialOpen: false },
              wp.element.createElement(TextControl, { label:'Palavra-chave (opcional)', value: manualKeyword, onChange:setManualKeyword, placeholder:'Vazio = Yoast ou Título' }),
              wp.element.createElement(Button, { variant:'secondary', onClick:suggestFromKeyword, disabled:isSuggesting }, isSuggesting? 'Buscando…' : 'Sugerir com base na palavra-chave'),
              suggestNotice ? wp.element.createElement(Notice, { status: suggestNotice.status, isDismissible:true, onRemove:()=> setSuggestNotice(null) }, suggestNotice.text) : null,
              suggests && suggests.length>0 && wp.element.createElement('div', { className:'akeno-suggest-list' },
                suggests.map((s,i)=> wp.element.createElement('div', { key:i, className:'akeno-suggest-item' }, s))
              )
            ),
            wp.element.createElement(PanelBody, { title: 'Botões (máx. 3)', initialOpen: true },
              getButtons().map((btn, idx)=>
                wp.element.createElement('div', { key: idx, className:'akeno-btn-config' },
                  wp.element.createElement(TextControl, { label:'TEXTO DO BOTÃO', value: btn.text || '', onChange:(v)=> onChangeText(idx, v) }),
                  wp.element.createElement(TextControl, { label:'Slug destino OU URL absoluta OU Post ID', help:'Ex.: 1709 (ID), "apps/placa" (slug) ou https://seusite.com/...', value: btn.dest || '', onChange:(v)=> onChangeDest(idx, v) }),
                  btn._preview ? wp.element.createElement('div', { className:'akeno-previewline' }, btn._preview) : null,
                  btn._url ? wp.element.createElement('div', { className:'akeno-previewurl' }, btn._url) : null,
                  wp.element.createElement(TextControl, { label:'Ícone (emoji)', value: btn.icon || '', onChange:(v)=> updateButton(idx, { icon: v }) }),
                  wp.element.createElement(ToggleControl, { label:'Usar src individual', checked: !!btn.src, onChange:(v)=> updateButton(idx, { src: !!v }) }),
                  !!btn.src && wp.element.createElement(TextControl, { label:'Valor do src individual', value: btn.srcValue || '', onChange:(v)=> updateButton(idx, { srcValue: v }) }),
                  wp.element.createElement(Button, { isDestructive:true, onClick:()=> removeButton(idx) }, 'Remover botão'),
                  wp.element.createElement('hr')
                )
              ),
              getButtons().length<3 && wp.element.createElement(Button, { variant:'primary', onClick:addButton }, 'Adicionar botão')
            ),
          ),
          wp.element.createElement('div', { className:'akeno-preview' },
            wp.element.createElement(ServerSideRender, { block: name, attributes })
          )
        );
      },
      save: ()=> null,
    });
  }

  registerAkeno('twod/akeno-interno', 'Akeno Interno');
})(window.wp);
