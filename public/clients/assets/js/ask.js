/* G6: giao diện hỏi đáp. Mỗi khối [data-ask] độc lập (trang /hoi-dap và khối "Hỏi nhanh" ở điểm đến).
   Chỉ dùng textContent (không innerHTML) nên nội dung từ máy chủ không thể chèn HTML. */
(function () {
    "use strict";
    var TYPE = { guide: "Cẩm nang", destination: "Điểm đến", food: "Ẩm thực", event: "Lễ hội", itinerary: "Lịch trình", fact: "Dữ kiện" };

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function fmtDate(s) {
        if (!s) return "";
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s);
        return m ? m[3] + "/" + m[2] + "/" + m[1] : "";
    }

    function init(root) {
        var log = root.querySelector("[data-ask-log]");
        var form = root.querySelector("[data-ask-form]");
        var quota = root.querySelector("[data-ask-quota]");
        var scope = root.getAttribute("data-scope") || "";
        var logged = root.getAttribute("data-logged") === "1";
        var busy = false;

        function bubble(kind, text) {
            var b = el("div", "ask__msg ask__msg--" + kind);
            b.appendChild(el("p", "ask__text", text));
            log.appendChild(b);
            b.scrollIntoView({ block: "nearest", behavior: "smooth" });
            return b;
        }

        function render(b, r) {
            if (r.notice) b.appendChild(el("p", "ask__notice", r.notice));
            if (r.sources && r.sources.length) {
                var ul = el("ul", "ask__sources");
                ul.appendChild(el("li", "ask__sources-h", r.cited ? "Nguồn" : "Nguồn tham khảo"));
                r.sources.forEach(function (s) {
                    var li = el("li");
                    var a = el("a", null, "[" + s.n + "] " + s.title);
                    a.href = s.url;
                    li.appendChild(a);
                    var meta = (TYPE[s.type] || "") + (s.updatedOn ? " · cập nhật " + fmtDate(s.updatedOn) : "");
                    if (meta) li.appendChild(el("span", "ask__src-meta", " " + meta));
                    ul.appendChild(li);
                });
                b.appendChild(ul);
            }
            if (r.refused && r.suggest && r.suggest.length) {
                var p = el("p", "ask__suggest");
                r.suggest.forEach(function (s, i) {
                    if (i) p.appendChild(document.createTextNode(" · "));
                    var a = el("a", null, s.title);
                    a.href = s.url;
                    p.appendChild(a);
                });
                b.appendChild(p);
            }
        }

        function send(q) {
            if (busy || !q) return;
            if (!logged) { window.location.href = root.getAttribute("data-login"); return; }
            busy = true;
            bubble("user", q);
            var wait = bubble("bot", "Đang tìm trong cẩm nang...");
            wait.classList.add("is-wait");
            var body = { question: q };
            if (scope) body.destination = scope;
            fetch(root.getAttribute("data-url"), {
                method: "POST",
                headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": root.getAttribute("data-csrf") || "" },
                credentials: "same-origin",
                body: JSON.stringify(body)
            }).then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (j) { return { status: res.status, json: j }; });
            }).then(function (x) {
                wait.remove();
                if (x.status === 401) { window.location.href = root.getAttribute("data-login"); return; }
                if (x.status === 200) {
                    var b = bubble("bot", x.json.answer);
                    render(b, x.json);
                    if (quota && x.json.remaining != null) quota.textContent = "Còn " + x.json.remaining + " lượt hỏi hôm nay.";
                } else {
                    var msg = x.status === 422 ? "Câu hỏi cần từ 3 ký tự." : (x.json && x.json.message) || "Có lỗi, vui lòng thử lại.";
                    bubble("bot", msg).classList.add("is-error");
                    if (x.status === 429 && quota) quota.textContent = "Đã hết lượt hôm nay.";
                }
            }).catch(function () {
                wait.remove();
                bubble("bot", "Không kết nối được máy chủ. Vui lòng thử lại.").classList.add("is-error");
            }).then(function () { busy = false; });
        }

        if (form) {
            form.addEventListener("submit", function (e) {
                e.preventDefault();
                var i = form.querySelector("input[name=question]");
                var q = i.value.trim();
                i.value = "";
                send(q);
            });
        }
        root.querySelectorAll("[data-ask-chip]").forEach(function (c) {
            c.addEventListener("click", function () { send(c.textContent.trim()); });
        });
    }

    document.querySelectorAll("[data-ask]").forEach(init);
})();
