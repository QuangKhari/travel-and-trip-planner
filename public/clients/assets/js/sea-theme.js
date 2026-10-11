/* Sea theme — chuyển động nhẹ + Spotlight điểm đến. JS thuần, không cần jQuery.
   Nạp SAU travel-theme.js (trong blocks/footer.blade.php). */
(function () {
    "use strict";
    var reduce =
        window.matchMedia &&
        matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* 1. Parallax rất nhẹ cho ảnh banner trang con (ảnh dịch chậm hơn trang) */
    var bannerBg = document.querySelector(".tv-banner__bg");
    if (bannerBg && !reduce) {
        var busy = false;
        var onScroll = function () {
            if (busy) return;
            busy = true;
            requestAnimationFrame(function () {
                var y = window.pageYOffset || 0;
                if (y < 700)
                    bannerBg.style.transform =
                        "translate3d(0," +
                        (Math.min(y, 350) * 0.14).toFixed(1) +
                        "px,0) scale(1.06)";
                busy = false;
            });
        };
        addEventListener("scroll", onScroll, { passive: true });
        onScroll();
    }

    /* 2. Hiện dần khi cuộn tới cho các khối chưa có hiệu ứng.
          Thêm/bớt selector ở đây nếu muốn. */
    if (!reduce && "IntersectionObserver" in window) {
        var sel = ".tv-panel, .td-day, .tv-spot, .tv-cta__box, .tv-footer__col";
        var els = [].slice
            .call(document.querySelectorAll(sel))
            .filter(function (n) {
                return (
                    !n.closest("[data-aos]") &&
                    n.getBoundingClientRect().top > window.innerHeight * 0.95
                );
            });
        var io = new IntersectionObserver(
            function (list) {
                list.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    en.target.classList.add("is-in");
                    io.unobserve(en.target);
                });
            },
            { rootMargin: "0px 0px -6% 0px" },
        );
        els.forEach(function (n, i) {
            n.classList.add("sea-reveal");
            n.style.transitionDelay = (i % 3) * 60 + "ms";
            io.observe(n);
        });
        // phòng hờ: sau 5 giây phần tử nào chưa hiện thì hiện luôn
        setTimeout(function () {
            els.forEach(function (n) {
                n.classList.add("is-in");
            });
        }, 5000);
    }

    /* 3. Spotlight điểm đến: bấm tab (miền) hoặc ảnh nhỏ để đổi ảnh lớn + tiêu đề */
    var spot = document.getElementById("tvSpot");
    if (spot) {
        var tabs = [].slice.call(spot.querySelectorAll("[data-spot-domain]"));
        var thumbs = [].slice.call(spot.querySelectorAll("[data-spot-thumb]"));
        var img = spot.querySelector("#tvSpotImg");
        var title = spot.querySelector("#tvSpotTitle");
        var meta = spot.querySelector("#tvSpotMeta");
        var kick = spot.querySelector("#tvSpotKicker");
        var cta = spot.querySelector("#tvSpotCta");
        var bg = spot.querySelector(".tv-spot__bg");
        var MAX = 4; // số ảnh nhỏ tối đa mỗi miền

        var show = function (t) {
            thumbs.forEach(function (x) {
                x.classList.toggle("is-on", x === t);
            });
            var apply = function () {
                img.src = t.dataset.img;
                img.alt = t.dataset.title;
                title.textContent = t.dataset.title;
                meta.textContent = t.dataset.meta;
                kick.textContent = t.dataset.domainName;
                cta.href = t.dataset.href;
                if (bg)
                    bg.style.backgroundImage = "url('" + t.dataset.img + "')";
                img.classList.remove("is-swap");
            };
            if (reduce) return apply();
            img.classList.add("is-swap");
            setTimeout(apply, 220);
        };

        var pick = function (domain) {
            tabs.forEach(function (x) {
                x.classList.toggle("is-on", x.dataset.spotDomain === domain);
            });
            var n = 0,
                first = null;
            thumbs.forEach(function (x) {
                var ok = x.dataset.domain === domain && n < MAX;
                x.hidden = !ok;
                if (ok) {
                    n++;
                    if (!first) first = x;
                }
            });
            if (first) show(first);
        };

        tabs.forEach(function (b) {
            b.addEventListener("click", function () {
                pick(b.dataset.spotDomain);
            });
        });
        thumbs.forEach(function (t) {
            t.addEventListener("click", function () {
                show(t);
            });
        });
        if (tabs.length) pick(tabs[0].dataset.spotDomain);
    }
})();
