<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $document['report_name'] }}</title>
    <link rel="stylesheet" href="{{ public_path('css/report-pdf.css') }}">
</head>
<body>
    <footer>Generado el {{ $document['generated_at']->format('d/m/Y H:i') }} · {{ $document['company'] }} · Página <span class="page-number"></span></footer>

    @foreach($document['sections'] as $section)
        <section class="report-section {{ $loop->first ? '' : 'new-page' }}">
            <header>
                <div class="company">{{ $document['company'] }}</div>
                <h1>{{ $section['title'] }}</h1>
                <table class="metadata"><tr><td><strong>Período:</strong> {{ $document['period'] }}</td><td><strong>Generado:</strong> {{ $document['generated_at']->format('d/m/Y H:i') }}</td></tr><tr><td><strong>Filtros:</strong> {{ $document['filters'] }}</td><td><strong>Moneda:</strong> {{ $document['currency'] }}</td></tr></table>
            </header>

            @if($section['metrics'] !== [])
                <table class="metrics"><tr>
                    @foreach($section['metrics'] as $metric)
                        <td><div class="metric-label">{{ $metric['label'] }}</div><div class="metric-value">@if($metric['type'] === 'money'){{ $document['currency'] }} {{ Illuminate\Support\Number::format((float) $metric['value'], precision: 2, locale: 'es') }}@elseif($metric['type'] === 'quantity'){{ Illuminate\Support\Number::format((float) $metric['value'], maxPrecision: 6, locale: 'es') }}@else{{ $metric['value'] }}@endif</div></td>
                        @if(($loop->iteration % 4) === 0 && ! $loop->last)</tr><tr>@endif
                    @endforeach
                </tr></table>
            @endif

            @if($section['columns'] !== [])
                <h2>Detalle</h2>
                <table class="detail">
                    <thead><tr>@foreach($section['columns'] as $column)<th>{{ $column['label'] }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse($section['rows'] as $row)
                            <tr>@foreach($section['columns'] as $column)<td class="{{ in_array($column['type'], ['money', 'quantity', 'integer'], true) ? 'numeric' : '' }}">@php($value = $row[$column['key']] ?? null)@if($value === null && $column['type'] === 'optional_date')No indicada@elseif($value === null)Pendiente de regularizar@elseif($value instanceof Carbon\CarbonInterface){{ $value->timezone($document['generated_at']->timezone)->format('d/m/Y H:i') }}@elseif($column['type'] === 'money'){{ $document['currency'] }} {{ Illuminate\Support\Number::format((float) $value, precision: 2, locale: 'es') }}@elseif($column['type'] === 'quantity'){{ Illuminate\Support\Number::format((float) $value, maxPrecision: 6, locale: 'es') }}@else{{ $value }}@endif</td>@endforeach</tr>
                        @empty
                            <tr><td colspan="{{ count($section['columns']) }}" class="empty">Sin datos para el período seleccionado.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endif
        </section>
    @endforeach
</body>
</html>
