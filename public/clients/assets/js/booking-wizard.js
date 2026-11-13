/*
 * C-05: wizard đặt tour 3 bước (trang /booking/{id}).
 *   Bước 1  chọn ngày khởi hành + số khách   (kiểm tra còn đủ chỗ bằng báo giá của máy chủ)
 *   Bước 2  thông tin liên hệ + mã giảm giá   (mã được máy chủ kiểm tra qua /dat-tour/bao-gia)
 *   Bước 3  xác nhận + chọn cách thanh toán   (gửi biểu mẫu thật tới /create-booking)
 *
 * Mọi số tiền trên trang chỉ để HIỂN THỊ và luôn lấy từ GET /dat-tour/bao-gia; BookingService tính lại toàn bộ khi đặt.
 * Không có JavaScript: biểu mẫu vẫn hiện đủ 3 phần và gửi bình thường.
 * Nút Back của trình duyệt quay lại bước trước (history.pushState với #buoc-N).
 */
(function () {
    "use strict";

    var form = document.getElementById("bkForm");
    if (!form || !form.hasAttribute("data-wizard")) return;

    var $ = function (s, r) { return (r || document).querySelector(s); };
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var money = function (n) { return Math.round(+n || 0).toLocaleString("vi-VN") + "đ"; };
    var toast = function (type, msg) {
        if (window.toastr && window.toastr[type]) window.toastr[type](msg);
    };

    var priceA = +form.dataset.priceAdult || 0;
    var priceC = +form.dataset.priceChild || 0;
    var quoteUrl = form.dataset.quoteUrl;
    var tourId = form.elements.tourId.value;
    var inAdults = $("#bkAdults"), inChildren = $("#bkChildren");
    var couponIn = $("#bkCoupon"), couponBtn = $("#bkCouponBtn"), couponMsg = $("#bkCouponMsg"), couponRemove = $("#bkCouponRemove");
    var agree = $("#bkAgree");

    var state = {
        step: 1,
        reached: Math.max(1, +form.dataset.step || 1),
        limit: +form.dataset.limit || 1,          // số chỗ còn của ngày đang chọn (tối đa 50 / đơn)
        coupon: "",                               // mã đã được máy chủ chấp nhận
        quote: null,                              // báo giá mới nhất của máy chủ
        seq: 0,
        timer: null,
        submitting: false,
    };

    form.classList.add("is-wizard");

    /* ------------------------------------------------------------------ bước */
    function panel(n) { return $('[data-panel="' + n + '"]', form); }

    function show(n, opts) {
        opts = opts || {};
        n = Math.max(1, Math.min(3, n));
        state.step = n;
        state.reached = Math.max(state.reached, n);
        form.dataset.step = String(n);
        $$(".bk-steppanel", form).forEach(function (p) {
            var on = +p.dataset.panel === n;
            p.classList.toggle("is-active", on);
            p.hidden = !on;
        });
        $$("[data-step-tab]").forEach(function (li) {
            var k = +li.dataset.stepTab;
            li.classList.toggle("is-now", k === n);
            li.classList.toggle("is-done", k < n);
            if (k === n) li.setAttribute("aria-current", "step"); else li.removeAttribute("aria-current");
            var b = $("button", li);
            if (b) b.disabled = k > state.reached;
        });
        var labels = { 1: "Tiếp tục: thông tin liên hệ", 2: "Tiếp tục: xác nhận", 3: "Xác nhận đặt tour" };
        $$("[data-bk-cta]").forEach(function (b) { if (!state.submitting) b.textContent = n === 3 ? payLabel() : labels[n]; });
        if (n === 3) fillReview();
        if (!opts.noHistory) {
            try { history[opts.replace ? "replaceState" : "pushState"]({ bkStep: n }, "", "#buoc-" + n); } catch (e) { /* trình duyệt chặn */ }
        }
        if (!opts.noScroll) {
            var top = $("#bkSteps");
            if (top && top.getBoundingClientRect().top < 0) top.scrollIntoView({ behavior: "smooth", block: "start" });
            var h = $("h2:not(.sr-only)", panel(n));
            if (h) { h.setAttribute("tabindex", "-1"); h.focus({ preventScroll: true }); }
        }
    }

    function payLabel() {
        var p = form.querySelector('input[name="payment"]:checked');
        var map = { momo: "Đặt tour và thanh toán MoMo", vnpay: "Đặt tour và thanh toán VNPay", qr: "Đặt tour và lấy mã QR" };
        return (p && map[p.value]) || "Xác nhận đặt tour";
    }

    /** Kiểm tra bước n; trả phần tử lỗi đầu tiên (hoặc null). */
    function validate(n) {
        if (n === 1) {
            var people = +inAdults.value + +inChildren.value;
            var box = $("#bkErr-seats");
            var q = state.quote;
            var msg = "";
            if (people > state.limit) msg = "Ngày này chỉ còn " + state.limit + " chỗ, không đủ cho " + people + " khách.";
            else if (q && q.ok && q.enough === false) msg = "Ngày này chỉ còn " + q.remaining + " chỗ, không đủ cho " + people + " khách.";
            if (box) { box.textContent = msg; box.classList.toggle("is-on", !!msg); }
            return msg ? (box || inAdults) : null;
        }
        if (n === 2) {
            var bad = null;
            fields.forEach(function (input) { if (!checkField(input) && !bad) bad = input; });
            return bad;
        }
        if (n === 3) {
            if (!agree.checked) {
                $("#bkErr-agree").textContent = "Vui lòng đồng ý với điều kiện đặt tour để tiếp tục.";
                return agree;
            }
            return null;
        }
        return null;
    }

    function next(to) {
        for (var k = 1; k < to; k++) {
            var bad = validate(k);
            if (bad) {
                if (state.step !== k) show(k);
                focusBad(bad);
                return false;
            }
        }
        // Có nhập mã nhưng chưa bấm "Áp dụng": kiểm tra mã trước khi sang bước xác nhận
        if (to === 3 && couponIn.value.trim() && couponIn.value.trim().toUpperCase() !== state.coupon) {
            applyCoupon(function (ok) { if (ok) show(3); else couponIn.focus(); });
            return false;
        }
        show(to);
        return true;
    }

    function focusBad(el) {
        el.scrollIntoView({ behavior: "smooth", block: "center" });
        if (el.focus) el.focus({ preventScroll: true });
        toast("error", "Vui lòng kiểm tra lại mục được đánh dấu đỏ.");
    }

    $$("[data-bk-next]").forEach(function (b) { b.addEventListener("click", function () { next(+b.dataset.bkNext); }); });
    $$("[data-bk-back]").forEach(function (b) { b.addEventListener("click", function () { show(+b.dataset.bkBack); }); });
    $$("[data-bk-goto]").forEach(function (b) {
        b.addEventListener("click", function () {
            var to = +b.dataset.bkGoto;
            if (to <= state.step || to <= state.reached) { if (to > state.step) next(to); else show(to); }
        });
    });
    $$("[data-bk-cta]").forEach(function (b) {
        b.addEventListener("click", function () {
            if (state.step < 3) next(state.step + 1);
            else if (form.requestSubmit) form.requestSubmit(); else form.submit();
        });
    });
    window.addEventListener("popstate", function (e) {
        var n = e.state && e.state.bkStep ? e.state.bkStep : (/^#buoc-(\d)$/.exec(location.hash) || [0, 1])[1];
        show(+n, { noHistory: true });
    });

    /* ------------------------------------------------------- ngày khởi hành */
    function currentDate() { return form.querySelector('input[name="availabilityId"]:checked'); }

    $$('input[name="availabilityId"][type="radio"]').forEach(function (r) {
        r.addEventListener("change", function () {
            $$(".bk-date").forEach(function (l) { l.classList.toggle("is-on", l.contains(r) && r.checked); });
            state.limit = Math.max(1, Math.min(50, +r.dataset.remaining || 1));
            var a = +inAdults.value, c = +inChildren.value;
            if (a + c > state.limit) {   // giữ trong giới hạn của ngày mới, báo cho người dùng biết
                c = Math.max(0, state.limit - a);
                a = Math.min(a, state.limit);
                inAdults.value = a; inChildren.value = c;
                toast("info", "Ngày này còn " + r.dataset.remaining + " chỗ, số khách đã được giảm cho vừa.");
            }
            $("#bkSumDate").textContent = r.dataset.start + " → " + r.dataset.end;
            requote(0);
        });
    });

    /* --------------------------------------------------------------- số khách */
    $$("[data-bk-step]").forEach(function (b) {
        b.addEventListener("click", function () {
            var isAdult = b.dataset["for"] === "adults";
            var dir = +b.dataset.bkStep;
            var a = +inAdults.value, c = +inChildren.value;
            if (isAdult) a += dir; else c += dir;
            if (a < 1 || c < 0 || a + c > state.limit) return;
            inAdults.value = a; inChildren.value = c;
            render();
            requote(300);   // PF-09: gộp các lần bấm liên tiếp thành một lần hỏi máy chủ
        });
    });

    /* ----------------------------------------------------------- báo giá (máy chủ) */
    function requote(delay, cb) {
        clearTimeout(state.timer);
        state.timer = setTimeout(function () { quote(state.coupon, cb); }, delay || 0);
    }

    function quoteKey(code) {
        var d = currentDate();
        return [inAdults.value, inChildren.value, d ? d.value : "", code === undefined ? state.coupon : code].join("|");
    }

    function quote(code, cb) {
        var my = ++state.seq;
        var d = currentDate();
        var key = quoteKey(code);
        var p = new URLSearchParams({ tourId: tourId, adults: inAdults.value, children: inChildren.value });
        if (d) p.set("availabilityId", d.value);
        else if (form.elements.availabilityId && form.elements.availabilityId.value) p.set("availabilityId", form.elements.availabilityId.value);
        if (code) p.set("coupon", code);
        $("#bkQuoteNote").textContent = "Đang tính giá…";
        return fetch(quoteUrl + "?" + p.toString(), { headers: { Accept: "application/json" }, credentials: "same-origin" })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); })
            .then(function (res) {
                if (my !== state.seq) return;
                var j = res.j || {};
                if (!j.ok) { $("#bkQuoteNote").textContent = j.message || "Chưa tính được giá lúc này."; cb && cb(false, j); return; }
                j.key = key;
                state.quote = j;
                if (typeof j.remaining === "number") {
                    state.limit = Math.max(1, Math.min(50, j.remaining));
                    var seat = d && $('[data-seat-for="' + d.value + '"]');
                    if (seat) { seat.textContent = "còn " + j.remaining + " chỗ"; seat.classList.toggle("is-low", j.remaining <= 5); }
                }
                $("#bkQuoteNote").textContent = j.enough === false
                    ? "Ngày này chỉ còn " + j.remaining + " chỗ."
                    : "Giá do máy chủ tính · còn " + j.remaining + " chỗ cho ngày này.";
                render();
                validate(1);
                cb && cb(true, j);
            })
            .catch(function () {
                if (my !== state.seq) return;
                $("#bkQuoteNote").textContent = "Không kết nối được để tính giá. Giá cuối cùng vẫn do hệ thống tính khi đặt.";
                cb && cb(false, null);
            });
    }

    /* --------------------------------------------------------------- mã giảm giá */
    function setCouponMsg(text, ok) {
        couponMsg.textContent = text || "";
        couponMsg.className = "bk-coupon__msg" + (text ? (ok ? " is-ok" : " is-bad") : "");
    }

    function applyCoupon(cb) {
        var code = couponIn.value.trim().toUpperCase();
        if (!code) { setCouponMsg("Vui lòng nhập mã giảm giá.", false); couponIn.focus(); cb && cb(false); return; }
        couponBtn.disabled = true;
        couponBtn.textContent = "Đang kiểm tra…";
        clearTimeout(state.timer);
        quote(code, function (ok, j) {
            couponBtn.disabled = false;
            couponBtn.textContent = "Áp dụng";
            if (ok && j && !j.couponError && j.discount > 0) {
                state.coupon = code;
                couponIn.value = code;
                couponIn.readOnly = true;
                couponBtn.hidden = true;
                couponRemove.hidden = false;
                setCouponMsg("Đã áp dụng: giảm " + (j.percent ? j.percent + "% · " : "") + money(j.discount) + ".", true);
                render();
                cb && cb(true);
            } else {
                state.coupon = "";
                setCouponMsg((j && j.couponError) || (j && j.message) || "Mã không hợp lệ.", false);
                if (ok) quote("");   // báo giá lại không có mã
                cb && cb(false);
            }
        });
    }

    couponBtn.addEventListener("click", function () { applyCoupon(); });
    couponRemove.addEventListener("click", function () {
        state.coupon = "";
        couponIn.readOnly = false;
        couponIn.value = "";
        couponRemove.hidden = true;
        couponBtn.hidden = false;
        setCouponMsg("");
        requote(0);
        couponIn.focus();
    });
    couponIn.addEventListener("keydown", function (e) {
        if (e.key === "Enter") { e.preventDefault(); if (!couponIn.readOnly) applyCoupon(); }
    });

    /* --------------------------------------------------------------- vẽ tóm tắt */
    function render() {
        var a = +inAdults.value, c = +inChildren.value;
        var subA = a * priceA, subC = c * priceC;
        // chỉ dùng số của máy chủ khi báo giá đúng với lựa chọn đang hiện; đang chờ báo giá mới thì tạm tính tại chỗ
        var q = state.quote && state.quote.key === quoteKey() ? state.quote : null;
        var discount = q && state.coupon ? +q.discount || 0 : 0;
        var total = q ? (state.coupon ? +q.total : +q.subtotal) : subA + subC;
        if (!q && state.coupon && state.quote && state.quote.percent) {
            discount = Math.round((subA + subC) * state.quote.percent / 100);
            total = subA + subC - discount;
        }
        $("#bkAdultsOut").textContent = a;
        $("#bkChildrenOut").textContent = c;
        $("#bkAdultsFormula").textContent = a + " × " + money(priceA);
        $("#bkAdultsSum").textContent = money(subA);
        $("#bkChildrenFormula").textContent = c + " × " + money(priceC);
        $("#bkChildrenSum").textContent = money(subC);
        $("#bkRowChildren").hidden = c === 0;
        $("#bkRowDiscount").hidden = !(discount > 0);
        $("#bkDiscountSum").textContent = "-" + money(discount);
        $("#bkDiscountLabel").textContent = discount > 0 ? "(mã " + state.coupon + ")" : "";
        $("#bkTotal").textContent = money(total);
        $("#bkBarTotal").textContent = money(total);
        $$("[data-bk-step]").forEach(function (b) {
            var isAdult = b.dataset["for"] === "adults";
            b.disabled = +b.dataset.bkStep < 0 ? (isAdult ? a <= 1 : c <= 0) : a + c >= state.limit;
        });
        var note = $("#bkGuestNote");
        if (note) note.textContent = (a + c) + " khách · tối đa " + state.limit + " khách cho ngày này" + (a + c >= state.limit ? " (đã đạt số chỗ còn lại)" : "");
    }

    function fillReview() {
        var d = currentDate();
        var dateTxt = d ? d.dataset.start + " → " + d.dataset.end : $("#bkSumDate").textContent.trim();
        var a = +inAdults.value, c = +inChildren.value;
        var set = function (k, v) { var x = $('[data-rv="' + k + '"]'); if (x) x.textContent = v; };
        set("date", dateTxt.replace(/\s+/g, " "));
        set("guests", a + " người lớn" + (c ? ", " + c + " trẻ em" : ""));
        set("contact", [form.elements.fullName.value, form.elements.tel.value, form.elements.email.value].filter(Boolean).join(" · "));
        set("coupon", state.coupon ? state.coupon + " (đã áp dụng)" : "Không dùng");
    }

    /* --------------------------------------------------------- kiểm tra ô nhập */
    var emailRe = /^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/;
    function normalizeTel(v) {
        var d = String(v || "").replace(/\D+/g, "");
        if (/^84\d{9,10}$/.test(d)) d = "0" + d.slice(2);
        return d;
    }
    var rules = {
        fullName: function (v) { return v.trim().length < 2 ? "Vui lòng nhập họ và tên." : ""; },
        tel: function (v) { return /^\d{10,11}$/.test(normalizeTel(v)) ? "" : "Số điện thoại phải có 10-11 chữ số."; },
        email: function (v) {
            v = v.trim();
            if (!v) return "Vui lòng nhập email.";
            if (v.length > 50) return "Email tối đa 50 ký tự.";
            return emailRe.test(v) ? "" : "Email chưa đúng (ví dụ: ten@gmail.com).";
        },
        address: function (v) { return v.trim().length < 5 ? "Vui lòng nhập địa chỉ (số nhà, đường, tỉnh/thành)." : ""; },
    };
    function checkField(input) {
        if (input.name === "tel") input.value = normalizeTel(input.value);
        var msg = rules[input.name](input.value);
        var box = $("#bkErr-" + input.name), wrap = input.closest(".bk-field");
        if (box) box.textContent = msg;
        if (wrap) wrap.classList.toggle("has-error", !!msg);
        if (msg) input.setAttribute("aria-invalid", "true"); else input.removeAttribute("aria-invalid");
        return !msg;
    }
    var fields = Object.keys(rules).map(function (n) { return form.elements[n]; });
    fields.forEach(function (input) {
        input.addEventListener("blur", function () { if (input.value !== "" || input.hasAttribute("aria-invalid")) checkField(input); });
        input.addEventListener("input", function () { if (input.hasAttribute("aria-invalid")) checkField(input); });
    });
    agree.addEventListener("change", function () { if (agree.checked) $("#bkErr-agree").textContent = ""; });

    form.addEventListener("change", function (e) {
        if (e.target.name !== "payment") return;
        $$(".bk-pay").forEach(function (l) { l.classList.toggle("is-on", l.contains(e.target)); });
        $$("[data-bk-cta], [data-bk-submit]").forEach(function (b) { if (state.step === 3 && !state.submitting) b.textContent = payLabel(); });
    });

    /* --------------------------------------------------------------- gửi */
    form.addEventListener("submit", function (e) {
        if (state.submitting) { e.preventDefault(); return; }
        for (var k = 1; k <= 3; k++) {
            var bad = validate(k);
            if (bad) { e.preventDefault(); show(k); focusBad(bad); return; }
        }
        // mã đã nhập nhưng chưa được chấp nhận: không gửi đi để tránh "đặt rồi mới biết mã sai"
        if (couponIn.value.trim() && couponIn.value.trim().toUpperCase() !== state.coupon) {
            e.preventDefault();
            show(2);
            applyCoupon();
            return;
        }
        state.submitting = true;
        $$("[data-bk-submit], [data-bk-cta]").forEach(function (b) {
            b.dataset.label = b.textContent;
            b.textContent = "Đang xử lý…";
            b.setAttribute("aria-busy", "true");
            b.disabled = true;
        });
    });
    window.addEventListener("pageshow", function (e) {
        if (!e.persisted) return;
        state.submitting = false;
        $$("[data-bk-submit], [data-bk-cta]").forEach(function (b) { b.removeAttribute("aria-busy"); b.disabled = false; if (b.dataset.label) b.textContent = b.dataset.label; });
    });

    /* --------------------------------------------------------------- khởi động */
    var startAt = state.reached;
    var h = /^#buoc-(\d)$/.exec(location.hash);
    if (h && +h[1] < startAt) startAt = +h[1];
    if (couponIn.value.trim()) {
        // quay lại từ lỗi máy chủ mà vẫn còn mã: kiểm tra lại để hiện đúng số tiền
        applyCoupon();
    } else {
        quote("");
    }
    show(startAt, { replace: true, noScroll: startAt === 1 });
    var srvErr = $("#bkServerErrors");
    if (srvErr) srvErr.focus();
})();
