<td style="width: 100%; background-color: #002d6a; color: #8096b5;">
    @foreach ($links as $link)
        @if ($link->is_not_first)
            &nbsp;&nbsp;/&nbsp;
        @endif
        <a  id="{{ $link->type }}" 
            class="tab_link" 
            @if ($link->mouseover)
                onmouseover="dropdownmenu(this, event, '{{ $link->droptype }}')"
            @endif
            href="{{ $link->url }}">
                {{ $link->name }}
        </a>

    @endforeach
</td>