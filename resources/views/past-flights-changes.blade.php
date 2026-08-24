<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'OzPAX') }} &mdash; Reroute Changes</title>

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
            }

            .status {
                background: #16321f;
                color: #86efac;
                border: 1px solid #22532f;
                padding: 0.75rem 1rem;
                border-radius: 0.5rem;
                margin-bottom: 1.5rem;
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

            td.count {
                text-align: right;
                font-variant-numeric: tabular-nums;
            }

            form {
                margin-top: 2rem;
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

            p.hint {
                color: #94a3b8;
                max-width: 40rem;
            }
        </style>
    </head>
    <body>
        <h1>Rerouted airports</h1>

        @if (session('status'))
            <div class="status">{{ session('status') }}</div>
        @endif

        <table>
            <thead>
                <tr>
                    <th>Originally filed</th>
                    <th></th>
                    <th>Rerouted to</th>
                    <th>Flights</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($changes as $change)
                    <tr>
                        <td>{{ $change['from'] }}</td>
                        <td>&rarr;</td>
                        <td>{{ $change['to'] }}</td>
                        <td class="count">{{ $change['count'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No reroutes recorded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <form method="POST" action="{{ route('past-flights.changes.recalculate') }}">
            @csrf
            <button type="submit">Recalculate all flights against current destination list</button>
            <p class="hint">
                Re-applies the international_destinations list to every recorded flight, from each
                flight's original dep/arr. Use this after adding or removing curated destinations so
                existing flights pick up the new options too, not just newly-recorded ones.
            </p>
        </form>
    </body>
</html>
