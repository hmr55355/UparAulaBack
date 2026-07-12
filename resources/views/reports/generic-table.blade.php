@php
    $cellColors = $cellColors ?? [];
    $footerLines = $footerLines ?? [];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 4px 0; }
        .meta { margin: 0 0 2px 0; color: #444; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #d9d9d9; font-weight: bold; }
        .footer { margin-top: 12px; }
        .footer p { margin: 2px 0; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    @foreach ($metaLines as $line)
        <p class="meta">{{ $line }}</p>
    @endforeach

    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $rowIndex => $row)
                <tr>
                    @foreach ($row as $colIndex => $value)
                        <td @if(!empty($cellColors[$rowIndex][$colIndex])) style="background:#{{ $cellColors[$rowIndex][$colIndex] }}" @endif>{{ $value }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (!empty($footerLines))
        <div class="footer">
            @foreach ($footerLines as $line)
                <p>{{ $line }}</p>
            @endforeach
        </div>
    @endif
</body>
</html>
