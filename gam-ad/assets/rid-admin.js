(function(){
  const box = document.getElementById('rid-box');
  if (!box) return;

  const list = document.getElementById('rid-list');
  const tpl  = document.getElementById('rid-item-tpl');
  const input= document.getElementById('rid_apps_json');
  const add  = document.getElementById('rid-add');
  const totalP = parseInt(box.dataset.paragraphs || '0', 10);

  function buildPosSelect(sel, value){
    sel.innerHTML = '';
    for (let i=0;i<=totalP;i++){
      const opt = document.createElement('option');
      opt.value = i;
      opt.textContent = (i===0) ? 'Antes do texto' : `Depois do ${i}º parágrafo`;
      if (i === (value||0)) opt.selected = true;
      sel.appendChild(opt);
    }
  }

  function serialize(){
    const items = [...list.querySelectorAll('.rid-item')].map(item => ({
      gplay: item.querySelector('.rid-gplay').value.trim(),
      appstore: item.querySelector('.rid-appstore').value.trim(),
      pos: parseInt(item.querySelector('.rid-pos').value || '0', 10)
    })).filter(x => x.gplay || x.appstore);
    input.value = JSON.stringify(items);
  }

  function addItem(data){
    const node = tpl.content.firstElementChild.cloneNode(true);
    const g = node.querySelector('.rid-gplay');
    const a = node.querySelector('.rid-appstore');
    const p = node.querySelector('.rid-pos');
    g.value = data?.gplay || '';
    a.value = data?.appstore || '';
    buildPosSelect(p, data?.pos || 0);

    node.querySelector('.rid-del').addEventListener('click', e => {
      e.preventDefault(); node.remove(); serialize();
    });
    ['input','change'].forEach(ev=>{
      g.addEventListener(ev, serialize);
      a.addEventListener(ev, serialize);
      p.addEventListener(ev, serialize);
    });

    // drag reorder
    node.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain','drag'); node.classList.add('dragging'); });
    node.addEventListener('dragend', ()=> node.classList.remove('dragging'));
    list.addEventListener('dragover', e => {
      e.preventDefault();
      const dragging = list.querySelector('.dragging');
      const after = [...list.querySelectorAll('.rid-item:not(.dragging)')]
        .find(el => e.clientY <= el.getBoundingClientRect().top + el.offsetHeight/2);
      if (!after) list.appendChild(dragging); else list.insertBefore(dragging, after);
    });

    list.appendChild(node);
  }

  // init with saved
  try {
    const saved = JSON.parse(input.value || '[]');
    if (saved.length) saved.forEach(addItem);
  } catch(e){}

  add.addEventListener('click', ()=> addItem({}));

  // ensure serialize before submit
  document.addEventListener('submit', serialize, true);
})();
