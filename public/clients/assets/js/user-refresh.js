/* User UI refresh — subtle scroll motion for client sub-pages. */
(function () {
    "use strict";
    var body = document.body;
    if (!body || !body.classList.contains("tv-sea")) return;
    var reduce =
        window.matchMedia &&
        matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduce) return;

    var banner = document.querySelector(".tv-banner__bg");
    var ticking = false;
    function scroll() {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(function () {
            var y = window.pageYOffset || 0;
            if (banner && y < 700)
                banner.style.transform =
                    "scale(1.045) translate3d(0," +
                    (y * 0.075).toFixed(1) +
                    "px,0)";
            ticking = false;
        });
    }
    addEventListener("scroll", scroll, { passive: true });
    scroll();

    if ("IntersectionObserver" in window) {
        var targets = document.querySelectorAll(
            ".td-section,.tv-panel,.tv-steps li,.blog-item.style-three,.blog-details-content,.tv-order",
        );
        var io = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (e) {
                    if (!e.isIntersecting) return;
                    e.target.classList.add("ux-visible");
                    io.unobserve(e.target);
                });
            },
            { rootMargin: "0px 0px -8% 0px" },
        );
        targets.forEach(function (el) {
            el.classList.add("ux-observe");
            io.observe(el);
        });
    }
})();
