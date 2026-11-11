<!DOCTYPE html>
<%-- Document of a case file (PDF). Override this template in the project for its letterhead. --%>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>$Title</title>
    <style>
        @page { size: A4; margin: 25mm 20mm 20mm 25mm; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11pt; line-height: 1.45; color: #111; }
        h1 { font-size: 14pt; margin: 0 0 12pt; }
        .flow-document-date { text-align: right; margin-bottom: 18pt; }
    </style>
</head>
<body>
    <div class="flow-document-date">$Date</div>
    $Body
</body>
</html>
