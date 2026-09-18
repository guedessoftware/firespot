(function(){
  const $  = (s) => document.querySelector(s);
  const seg = $('#segment_type');
  const blocks = {
    sexo: $('#seg_sexo'),
    idade: $('#seg_idade'),
    aniversario: $('#seg_aniversario'),
    busca: $('#seg_busca'),
    manual: $('#seg_manual')
  };
  function updateSeg(){
    const val = seg.value;
    for (const k in blocks) { if (!blocks[k]) continue; blocks[k].hidden = (k!==val); }
  }
  seg.addEventListener('change', updateSeg);
  updateSeg();
})();
