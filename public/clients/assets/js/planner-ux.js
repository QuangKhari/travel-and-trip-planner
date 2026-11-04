/* Travela Planner UX v1: nâng cấp tăng dần, không thay đổi engine hay ghi DB. */
(function () {
    'use strict';
    var root = document.getElementById('tpRoot');
    if (!root) return;
    var $ = function (id) { return document.getElementById(id); };
    var mobileAI = $('tpuxMobAi');
    function isMobile() { return window.matchMedia('(max-width: 991.98px)').matches; }
    function showPlan() {
        if (document.documentElement.classList.contains('tpx-map-open') && $('tpMapClose')) $('tpMapClose').click();
    }
    if (mobileAI) {
        mobileAI.addEventListener('click', function () {
            showPlan();
            var drawer = $('aiCo');
            if (drawer && !drawer.hidden) { $('aiCoClose').click(); return; }
            if ($('aiCoOpen')) $('aiCoOpen').click();
        });
    }
    document.addEventListener('travela:copilot', function (event) {
        if (!mobileAI) return;
        var open = !!(event.detail && event.detail.open);
        mobileAI.classList.toggle('is-on', open);
        mobileAI.setAttribute('aria-expanded', String(open));
    });
    function jumpHealth() {
        showPlan();
        if ($('tpTabPlan') && $('tpTabPlan').getAttribute('aria-selected') !== 'true') $('tpTabPlan').click();
        var health = $('tpHealth');
        if (!health) return;
        var toggle = health.querySelector('.tpx-health__t');
        if (toggle && toggle.getAttribute('aria-expanded') === 'false') toggle.click();
        health.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (toggle) toggle.focus({ preventScroll: true });
    }
    if ($('tpuxHealthBtn')) $('tpuxHealthBtn').addEventListener('click', jumpHealth);
    if ($('tpuxJumpToday')) $('tpuxJumpToday').addEventListener('click', function () {
        var active = document.querySelector('#tpDayNav .is-on, #tpSideDays .is-on');
        var day = active && active.getAttribute('data-day');
        var target = day ? document.querySelector('#tpDaysBox .tpx-day[data-day="' + day.replace(/[^0-9]/g, '') + '"]') : null;
        if (!target) target = document.querySelector('#tpDaysBox .tpx-day');
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    // Nhận biết cảnh báo qua nút Trip Health đã được render bởi planner.js.
    var health = $('tpHealth');
    var mirror = $('tpuxHealthText'), hbtn = $('tpuxHealthBtn');
    var scheduled = false;
    function syncHealth() {
        scheduled = false;
        if (!health || !mirror) return;
        var title = health.querySelector('.tpx-health__sum');
        var error = health.classList.contains('is-err');
        var warn = health.classList.contains('is-bad');
        mirror.textContent = title ? title.textContent.trim() : 'Kiểm tra lịch trình';
        hbtn.classList.toggle('is-error', error);
        hbtn.classList.toggle('is-warning', warn && !error);
    }
    if (health && window.MutationObserver) {
        new MutationObserver(function () {
            if (scheduled) return;
            scheduled = true;
            requestAnimationFrame(syncHealth);
        }).observe(health, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
        syncHealth();
    }
    // Đảm bảo nút lưu luôn báo trạng thái khi focus, không dựa duy nhất vào màu sắc.
    var save = $('tpMsg');
    if (save) {
        save.setAttribute('aria-atomic', 'true'); save.setAttribute('role', 'status');
        function syncSave() {
            var message = (save.textContent || '').toLowerCase();
            save.classList.toggle('tpux-save--error', /mất kết nối|không lưu|lưu thất bại|lỗi lưu|xung đột/.test(message));
            save.classList.toggle('tpux-save--busy', /đang lưu|chưa lưu/.test(message));
        }
        if (window.MutationObserver) new MutationObserver(syncSave).observe(save, { childList: true, subtree: true, characterData: true });
        syncSave();
    }
    // Mobile: không để hộp thoại AI chồng lên sheet bản đồ.
    window.addEventListener('resize', function () {
        if (!isMobile() && mobileAI) mobileAI.classList.remove('is-on');
    });
})();
