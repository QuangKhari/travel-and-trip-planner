@foreach($list_promotion as $promotion)

<tr>

```
<td>{{ $promotion->code }}</td>

<td>{{ $promotion->description }}</td>

<td>{{ $promotion->discount }}%</td>

<td>
    {{ date('d-m-Y', strtotime($promotion->startDate)) }}
</td>

<td>
    {{ date('d-m-Y', strtotime($promotion->endDate)) }}
</td>

<td>{{ $promotion->quantity }}</td>

<td>
    @if($promotion->status == 'y')
        <span class="badge badge-success">
            Hoạt động
        </span>
    @else
        <span class="badge badge-danger">
            Ngừng hoạt động
        </span>
    @endif
</td>

<td>

    <div class="btn-group">

        <button type="button"
            class="btn btn-primary btn-sm dropdown-toggle"
            data-toggle="dropdown">
            Thao tác
        </button>

        <div class="dropdown-menu">

            <a class="dropdown-item"
            href="{{ url('/admin/promotion-edit/'.$promotion->promotionId) }}">
                Sửa
            </a>

            <form action="{{ route('admin.delete-promotion') }}"
                  method="POST"
                  onsubmit="return confirm('Bạn có chắc muốn xóa voucher này?')">

                @csrf

                <input type="hidden"
                       name="promotionId"
                       value="{{ $promotion->promotionId }}">

                <button type="submit"
                        class="dropdown-item text-danger">
                    Xóa
                </button>

            </form>

        </div>

    </div>

</td>
```

</tr>

@endforeach
