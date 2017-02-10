<div class="row">
    <div class="col-md-12">
        @foreach($mentees as $mentee)
            <div class="col-md-3">
                @include('mentees.single', ['mentee' => $mentee])
            </div>
        @endforeach
    </div>
</div>
@include('mentees.modals')
@section('additionalFooter')
    <script>
        $( document ).ready(function() {
            var controller = new window.MenteesListController();
            controller.init();
        });
    </script>
@endsection