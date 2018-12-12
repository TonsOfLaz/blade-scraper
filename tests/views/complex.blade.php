<div id="testing">

	<h1>{{ $page_title }}</h1>

	@if ($has_section)
		{{ $section }}
	@endif
	<br>
	@if ($has_sponsor)
		<div id="sponsor">
			{{ $sponsor }}
		</div>
		@if ($attorney_general)
			(Attorney General)
		@endif
	@endif

	@foreach ($bills as $bill)
		<div class="bill">
			<b>{{ $bill->title }}</b>
			@if ($bill->is_docket)
				<div class="docket_number">{{ $bill->docket_number }}</div>
			@endif
			<b>Actions</b>
			@foreach ($bill->actions as $action)
				<div class="action">
					{{ $action->date }}<br>
					{{ $action->note }}
				</div>
				@if($action->laz)
					@foreach($action->whats as $what)
						<div class="what">
							{{ $what->now }}
						</div>
					@endforeach
				@endif
			@endforeach
			<b>Cosponsors</b>
			@foreach ($bill->cosponsors as $cosponsor)
				<div class="cosponsor">
					{{ $cosponsor->id }}<br>
					{{ $cosponsor->name }}<br>
					{{ $cosponsor->district }}<br>
					{{ $bill->samevalue }}

				</div>
			@endforeach
		</div>
	@endforeach

</div>