/* tv3.js — dùng chung cho các trang giao diện mới (tv3.css): hiện dần khối .v3-rv khi cuộn tới. */
(function () {
    var els = document.querySelectorAll(".v3-rv");
    if (!els.length || !("IntersectionObserver" in window)) return;
    document.documentElement.classList.add("v3-js");
    var io = new IntersectionObserver(
        function (en) {
            en.forEach(function (x) {
                if (x.isIntersecting) {
                    x.target.classList.add("is-in");
                    io.unobserve(x.target);
                }
            });
        },
        { rootMargin: "0px 0px -8% 0px" }
    );
    els.forEach(function (el) {
        io.observe(el);
    });
})();
