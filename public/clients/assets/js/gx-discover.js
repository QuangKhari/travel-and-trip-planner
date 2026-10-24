/* Hero 3 mục (ảnh ghép ô đổi theo mục) và accordion ẩm thực. JS thuần, không phụ thuộc jQuery. */
(function () {
    "use strict";
    var reduce = matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* ---------- accordion ---------- */
    [].forEach.call(document.querySelectorAll("[data-fa]"), function (ul) {
        var items = [].slice.call(ul.querySelectorAll(".fa-item"));
        function on(it) {
            items.forEach(function (x) { x.classList.toggle("is-on", x === it); });
        }
        items.forEach(function (it) {
            it.addEventListener("mouseenter", function () { on(it); });
            it.addEventListener("focusin", function () { on(it); });
        });
    });

    /* ---------- hero ghép ô ---------- */
    [].forEach.call(document.querySelectorAll("[data-gh]"), function (root) {
        var items = [].slice.call(root.querySelectorAll(".gh-item"));
        var stage = root.querySelector(".gh-tiles");
        var cols = +root.dataset.cols || 3, rows = +root.dataset.rows || 3;
        if (!items.length || !stage) return;

        var tiles = [];
        stage.style.gridTemplateColumns = "repeat(" + cols + ",1fr)";
        stage.style.gridTemplateRows = "repeat(" + rows + ",1fr)";
        for (var r = 0; r < rows; r++) {
            for (var c = 0; c < cols; c++) {
                var t = document.createElement("span");
                t.className = "gh-tile";
                t.style.transitionDelay = ((r + c) % 5) * 40 + "ms";
                stage.appendChild(t);
                tiles.push(t);
            }
        }

        var cache = {}, current = -1, timer = 0, shown = "";
        function load(url, cb) {
            var im = cache[url];
            if (im && im.complete && im.naturalWidth) return cb(im);
            if (!im) { im = cache[url] = new Image(); im.src = url; }
            im.addEventListener("load", function () { cb(im); }, { once: true });
        }
        /* trải ảnh kiểu object-fit: cover lên cả khung, mỗi ô lấy đúng phần của mình */
        function paint(im, url) {
            /* offsetLeft/Top không bị ảnh hưởng bởi transform scale của ô đang ẩn */
            var W = stage.offsetWidth, H = stage.offsetHeight;
            if (!W) return;
            var k = Math.max(W / im.naturalWidth, H / im.naturalHeight);
            var dw = im.naturalWidth * k, dh = im.naturalHeight * k;
            var ox = (W - dw) / 2, oy = (H - dh) / 2;
            tiles.forEach(function (t) {
                t.style.backgroundImage = 'url("' + url.replace(/"/g, "%22") + '")';
                t.style.backgroundSize = dw + "px " + dh + "px";
                t.style.backgroundPosition = ox - t.offsetLeft + "px " + (oy - t.offsetTop) + "px";
            });
        }
        function setImg(url, animate) {
            load(url, function (im) {
                root.classList.add("is-tiled");
                if (!animate || reduce) { paint(im, url); shown = url; return; }
                tiles.forEach(function (t) { t.classList.add("is-out"); });
                clearTimeout(timer);
                timer = setTimeout(function () {
                    paint(im, url); shown = url;
                    requestAnimationFrame(function () {
                        tiles.forEach(function (t) { t.classList.remove("is-out"); });
                    });
                }, 280);
            });
        }
        function show(i) {
            if (i === current) return;
            var first = current === -1;
            current = i;
            items.forEach(function (it, k) {
                it.classList.toggle("is-on", k === i);
                if (k === i) it.setAttribute("aria-current", "true"); else it.removeAttribute("aria-current");
            });
            setImg(items[i].dataset.img, !first);
        }

        items.forEach(function (it, i) {
            it.addEventListener("mouseenter", function () { show(i); });
            it.addEventListener("focus", function () { show(i); });
        });
        items.forEach(function (it) { load(it.dataset.img, function () {}); }); /* tải trước để đổi ảnh không giật */
        show(0);
        addEventListener("resize", function () {
            if (shown) load(shown, function (im) { paint(im, shown); });
        });
    });
})();
