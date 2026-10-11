/* TravelTrip Client UI Polish v4 — tiny, non-invasive motion layer. */
(function () {
    "use strict";
    var reduce =
        window.matchMedia &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduce) return;

    /* Reveal only elements that do not already use AOS/sea-theme reveal. */
    if ("IntersectionObserver" in window) {
        var selectors = [
            ".td-gallery",
            ".td-layout",
            ".tv-booking .booking-info",
            ".tv-booking .booking-summary",
            ".tv-profile__grid",
            ".tc-contact__shell",
            ".tv-empty",
        ];
        var nodes = document.querySelectorAll(selectors.join(","));
        var io = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add("ui-polish-in");
                    io.unobserve(entry.target);
                });
            },
            { rootMargin: "0px 0px -8% 0px", threshold: 0.08 },
        );
        nodes.forEach(function (node) {
            if (node.closest("[data-aos]")) return;
            node.classList.add("ui-polish-reveal");
            io.observe(node);
        });
    }

    /* Close accidental horizontal scroll caused by legacy widgets. */
    document.documentElement.classList.add("ui-polish-ready");
})();
