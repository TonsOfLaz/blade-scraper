<span id="lblBills">
@foreach ($bills as $bill)
	<div>
		{{ $bill->chamber_type }} No.
		<a href="{{ $bill->pdf_link }}" style="text-decoration:underline">
			{{ $bill->number }}
		</a>
	</bill>
	@if ($bill->has_other_sponsor)
		<div>({{ $bill->other_sponsor }})</div>
	@endif
	<div>
		<b>BY</b>
		&nbsp;&nbsp;{{ $bill->sponsors }}
	</div>
	<div>
		<b>ENTITLED,&nbsp;</b>
		{!! $bill->title !!} 
		@if ($bill->has_summary)
			({!! $bill->summary !!})
		@endif
	</div>
	<div style="margin-left: 20%">
		{{ $bill->ri_id }}
	</div>
	@foreach ($bill->actions as $action)
	<div style="margin-left: 5%">
		{{ $action->text }}
	</div>
	@endforeach
	<br /><br />
@endforeach
<br /><br />
  <div>
 <table style="width:400px">
        <tr>
            <td style="width:300px">Total Bills: {{ $total_bills }}<br /><br /></td>
            <td style="width:100px"></td>
        </tr>
        <tr>
            <td>{{ $generate_location }}</td>
            <td>{{ $generate_date }}</td>
        </tr>
        <tr>
            <td>State House, Providence, Rhode Island</td>
            <td>{{ $generate_time }}</td>
        </tr>
    </table>
</span>
