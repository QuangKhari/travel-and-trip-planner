<ul class="nav nav-tabs" style="margin-bottom:12px">
    <li class="{{ $tab === 'chat' ? 'active' : '' }}"><a href="{{ route('admin.chat') }}">Hỏi đáp (cẩm nang + hỏi về chuyến)</a></li>
    <li class="{{ $tab === 'ai' ? 'active' : '' }}"><a href="{{ route('admin.chat.ai') }}">AI La bàn (F1 · F2 · F3)</a></li>
</ul>
