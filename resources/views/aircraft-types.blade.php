<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'OzPAX') }} &mdash; Aircraft Types</title>

        <style>
            body {
                margin: 0;
                padding: 2rem;
                font-family: ui-sans-serif, system-ui, sans-serif;
                background: #0c1420;
                color: #e2e8f0;
            }

            h1 {
                margin-top: 0;
                margin-bottom: 0.25rem;
            }

            p.hint {
                color: #94a3b8;
                max-width: 44rem;
            }

            .status, .error {
                padding: 0.75rem 1rem;
                border-radius: 0.5rem;
                margin-bottom: 1.5rem;
                max-width: 48rem;
            }

            .status {
                background: #16321f;
                color: #86efac;
                border: 1px solid #22532f;
            }

            .error {
                background: #3b1a1a;
                color: #fca5a5;
                border: 1px solid #7f1d1d;
            }

            table {
                border-collapse: collapse;
                width: 100%;
                max-width: 48rem;
            }

            th, td {
                text-align: left;
                padding: 0.5rem 1rem;
                border-bottom: 1px solid #1e293b;
            }

            th {
                color: #94a3b8;
                font-weight: 600;
                text-transform: uppercase;
                font-size: 0.75rem;
                letter-spacing: 0.05em;
            }

            td.icao {
                font-family: ui-monospace, monospace;
                font-weight: 600;
            }

            td.name {
                color: #94a3b8;
            }

            td.count {
                text-align: right;
                font-variant-numeric: tabular-nums;
                width: 6rem;
            }

            /* Frequency bar - the point of the page is reading the
               distribution at a glance, which 142 rows of bare numbers
               don't give you. Width is relative to the most-flown type,
               not the total, so the long tail stays visible. */
            td.share {
                width: 12rem;
            }

            .bar {
                background: #38bdf8;
                height: 0.5rem;
                border-radius: 0.25rem;
                min-width: 2px;
            }

            form {
                margin: 2rem 0 0.5rem;
            }

            button {
                background: #38bdf8;
                color: #0c1420;
                border: none;
                padding: 0.6rem 1.2rem;
                border-radius: 0.4rem;
                font-weight: 600;
                cursor: pointer;
            }

            button:hover {
                background: #7dd3fc;
            }

            details {
                max-width: 48rem;
                margin-bottom: 2rem;
                border: 1px solid #1e293b;
                border-radius: 0.5rem;
                padding: 0.75rem 1rem;
            }

            summary {
                cursor: pointer;
                font-weight: 600;
            }

            summary .when {
                color: #64748b;
                font-weight: 400;
                font-size: 0.8rem;
                margin-left: 0.5rem;
            }

            details table {
                margin-top: 1rem;
            }

            .empty {
                color: #94a3b8;
                margin: 1rem 0 0;
            }
        </style>
    </head>
    <body>
        <h1>Aircraft types</h1>
        <p class="hint">
            Every aircraft type filed across {{ number_format($totalFlights) }} recorded flights,
            most-flown first.
        </p>

        @if (session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="error">{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('aircraft-types.check') }}">
            @csrf
            <button type="submit">Check vatSys Performance.xml coverage</button>
        </form>

        <details>
            <summary>
                @if ($lastCheck)
                    Missing from Performance.xml ({{ count($lastCheck['missing']) }})
                    <span class="when">
                        last run {{ \Illuminate\Support\Carbon::parse($lastCheck['checked_at'])->format('j M Y H:i') }} UTC
                    </span>
                @else
                    Missing from Performance.xml
                    <span class="when">never run</span>
                @endif
            </summary>

            @if (! $lastCheck)
                <p class="empty">
                    Run the check above to compare every recorded type against the aircraft
                    profiles in vatSys's australia-dataset.
                </p>
            @elseif ($lastCheck['missing'] === [])
                <p class="empty">
                    All {{ $lastCheck['observed_types'] }} recorded types are covered by the
                    {{ number_format($lastCheck['dataset_types']) }} profiles in the dataset.
                </p>
            @else
                <p class="empty">
                    Filed on the network but absent from the
                    {{ number_format($lastCheck['dataset_types']) }} profiles in the dataset, so
                    they have no performance data for ATC to plan against &mdash; these are the
                    types to add.
                </p>

                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Flights</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lastCheck['missing'] as $type)
                            <tr>
                                <td class="icao">{{ $type['icao'] }}</td>
                                <td class="count">{{ number_format($type['flights']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </details>

        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Name</th>
                    <th>Flights</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($types as $type)
                    <tr>
                        <td class="icao">{{ $type['icao'] }}</td>
                        <td class="name">{{ $type['name'] ?? '—' }}</td>
                        <td class="count">{{ number_format($type['flights']) }}</td>
                        <td class="share">
                            <div class="bar" style="width: {{ round($type['flights'] / $maxFlights * 100, 2) }}%"></div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No flights recorded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </body>
</html>
