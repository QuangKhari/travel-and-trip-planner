<nav class="ad-tabs" aria-label="Nhật ký hỏi đáp">
    <a href="{{ route('admin.chat') }}" class="{{ $tab === 'chat' ? 'is-on' : '' }}">Hỏi đáp (cẩm nang + hỏi về chuyến)</a>
    <a href="{{ route('admin.chat.ai') }}" class="{{ $tab === 'ai' ? 'is-on' : '' }}">AI La bàn (F1 · F2 · F3)</a>
</nav>
