<div class="card">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="fas fa-chart-bar"></i> Research Results</h5>
    </div>
    <div class="card-body">
        @if(isset($results['stats']) && count($results['stats']) > 0)
            @foreach($results['stats'] as $stat)
                <div class="mb-4">
                    <h6>{{ $stat['purpose'] }}</h6>
                    @if(isset($stat['error']))
                        <div class="alert alert-danger">{{ $stat['error'] }}</div>
                    @else
                        @if(is_array($stat['result']))
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            @foreach(array_keys(reset($stat['result'])) as $key)
                                                <th>{{ ucfirst(str_replace('_', ' ', $key)) }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($stat['result'] as $row)
                                            <tr>
                                                @foreach($row as $value)
                                                    <td>{{ is_array($value) ? json_encode($value) : $value }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="alert alert-info">
                                <strong>Result:</strong> {{ $stat['result'] }}
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach
        @else
            <p class="text-muted">No results available.</p>
        @endif
    </div>
</div>
