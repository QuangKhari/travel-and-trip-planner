/* Nature Luxury UX utilities: progressive enhancement, no framework dependency. */
(function () {
    'use strict';
    var search = document.getElementById('nlGuideSearch');
    if (search) {
        var cards = Array.prototype.slice.call(document.querySelectorAll('#nlGuideContent .hb-grid > *'));
        var status = document.getElementById('nlGuideCount');
        function filter() {
            var term = search.value.trim().toLocaleLowerCase('vi-VN');
            var shown = 0;
            cards.forEach(function (card) {
                var match = card.textContent.toLocaleLowerCase('vi-VN').indexOf(term) >= 0;
                card.hidden = !match;
                if (match) shown++;
            });
            if (status) status.textContent = term ? shown + ' bài phù hợp trên trang này' : '';
        }
        search.addEventListener('input', filter);
    }
    var tourList = document.getElementById('nlFavTours');
    if (tourList) {
        var ids = [], meta = {};
        try {
            ids = JSON.parse(localStorage.getItem('wt_fav') || '[]');
            meta = JSON.parse(localStorage.getItem('wt_fav_meta') || '{}');
        } catch (e) { /* private mode or corrupted cache */ }
        if (!Array.isArray(ids)) ids = [];
        ids = ids.filter(function (id) { return /^[a-zA-Z0-9_-]{1,64}$/.test(String(id)); });
        if (!meta || typeof meta !== 'object') meta = {};
        tourList.textContent = '';
        var rendered = 0;
        ids.forEach(function (id) {
            var t = meta[String(id)];
            if (!t || !t.title || !t.url) return;
            var href;
            try {
                href = new URL(t.url, location.origin);
                if (href.origin !== location.origin || !/^(http:|https:)$/.test(href.protocol)) return;
            } catch (e) { return; }
            var card = document.createElement('article');
            card.className = 'nl-library__item';
            var chip = document.createElement('span');chip.className='nl-library__tag';chip.textContent='Tour đã thích';
            var h = document.createElement('h3');
            var link = document.createElement('a');link.href=href.href;link.textContent=String(t.title);
            h.appendChild(link);card.appendChild(chip);card.appendChild(h);
            if (t.dest) {var d=document.createElement('p');d.textContent=String(t.dest);card.appendChild(d);}
            var remove = document.createElement('button');
            remove.type = 'button'; remove.className='nl-tour-remove';
            remove.textContent='Bỏ yêu thích';remove.setAttribute('aria-label','Bỏ yêu thích tour '+String(t.title));
            remove.addEventListener('click',function(){
                var next=ids.filter(function(x){return String(x)!==String(id);});
                try {localStorage.setItem('wt_fav',JSON.stringify(next));delete meta[String(id)];localStorage.setItem('wt_fav_meta',JSON.stringify(meta));}
                catch(e){return;}
                ids=next;card.remove();
                if(!tourList.querySelector('.nl-library__item')) {
                    var empty=document.createElement('p');empty.textContent='Bạn chưa lưu tour nào trên thiết bị này.';tourList.appendChild(empty);
                }
                document.dispatchEvent(new CustomEvent('travela:favorites-changed'));
            });
            card.appendChild(remove);tourList.appendChild(card);rendered++;
        });
        if (!rendered) {
            var empty = document.createElement('p');
            empty.textContent = 'Trình duyệt này chưa có tour đã lưu, hoặc thông tin tour cũ không còn trong bộ nhớ.';
            tourList.appendChild(empty);
        }
    }
})();
