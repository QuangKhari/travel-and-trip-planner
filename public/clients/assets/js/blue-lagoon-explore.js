/* Local filter for currently rendered inspiration results only; does not replace server-side domain filter. */
(() => {
  'use strict';
  const search = document.getElementById('blExploreSearch');
  const grid = document.getElementById('blExploreGrid');
  const count = document.getElementById('blExploreCount');
  const empty = document.getElementById('blExploreEmpty');
  const clear = document.getElementById('blExploreClear');
  if (!search || !grid || !count || !empty) return;
  const cards = [...grid.querySelectorAll('[data-bl-search]')];
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').toLowerCase().trim();
  const update = () => {
    const term = normalize(search.value);
    let visible = 0;
    for (const card of cards) {
      const match = !term || normalize(card.dataset.blSearch).includes(term);
      card.hidden = !match;
      if (match) visible++;
    }
    count.textContent = `${visible} / ${cards.length} điểm đến`;
    empty.hidden = visible !== 0;
  };
  search.addEventListener('input', update);
  if (clear) clear.addEventListener('click', () => { search.value = ''; update(); search.focus(); });
})();
