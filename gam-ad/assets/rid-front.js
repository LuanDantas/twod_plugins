// tenta herdar a cor primária do tema em runtime
(function(){
  try{
    var names = [
      '--wp--preset--color--primary',
      '--global--color-primary',
      '--ast-global-color-0',
      '--primary-color',
      '--theme-primary',
      '--primary'
    ];
    var root = document.documentElement;
    var cs = getComputedStyle(root), color = '';
    for (var i=0;i<names.length;i++){
      var v = cs.getPropertyValue(names[i]).trim();
      if (v){ color = v; break; }
    }
    if (!color && document.body){
      cs = getComputedStyle(document.body);
      for (var j=0;j<names.length;j++){
        var v2 = cs.getPropertyValue(names[j]).trim();
        if (v2){ color = v2; break; }
      }
    }
    if (color) root.style.setProperty('--rid-theme-primary', color);
  }catch(e){}
})();
