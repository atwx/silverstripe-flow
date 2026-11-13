<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>$Title $Reference</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #222; }
        h1 { font-size: 16pt; margin: 0 0 4pt; }
        .meta { color: #555; margin-bottom: 14pt; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
        th, td { text-align: left; vertical-align: top; padding: 3pt 4pt; border-bottom: 0.5pt solid #ddd; }
        th { width: 38%; font-weight: normal; color: #555; }
        h2 { font-size: 11.5pt; margin: 12pt 0 4pt; }
        h3 { font-size: 10.5pt; margin: 8pt 0 2pt; }
    </style>
</head>
<body>
    <h1>$Title</h1>
    <div class="meta"><%t Atwx\\Flow\\Intake\\IntakeReceipt.RECEIVED 'Reference {reference}, received on {date}' reference=$Reference date=$Date %></div>
    <% if $Intro %><div class="intro">$Intro</div><% end_if %>
    <table>
        <% loop $Rows %>
            <% if $Rows.Count %>
                </table>
                <h2>$Title</h2>
                <% loop $Rows %>
                    <% if $Rows.Count %>
                        <h3>$Title</h3>
                        <table><% loop $Rows %><tr><th>$Title</th><td>$Value</td></tr><% end_loop %></table>
                    <% else %>
                        <table><tr><th>$Title</th><td>$Value</td></tr></table>
                    <% end_if %>
                <% end_loop %>
                <table>
            <% else %>
                <tr><th>$Title</th><td>$Value</td></tr>
            <% end_if %>
        <% end_loop %>
    </table>
    <% if $Files.Count %>
        <h2><%t Atwx\\Flow\\Intake\\IntakeReceipt.FILES 'Files' %></h2>
        <ul><% loop $Files %><li>$Name</li><% end_loop %></ul>
    <% end_if %>
</body>
</html>
