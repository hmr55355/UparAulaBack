<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 4px 0; }
        h2 { font-size: 13px; margin: 16px 0 6px 0; border-bottom: 1px solid #ccc; padding-bottom: 2px; }
        p.meta { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #d9d9d9; }
        .promoted { color: #2E7D32; }
        .not-promoted { color: #C62828; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    @foreach ($bulletins as $bulletin)
        <div @class(['page-break' => ! $loop->last])>
            @include('reports.partials.bulletin', $bulletin)
        </div>
    @endforeach
</body>
</html>
