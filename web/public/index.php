<!DOCTYPE html>
<html lang="en" xmlns="">
<head>
    <meta charset="UTF-8">
    <title>Convert Python code to PHP</title>
</head>
<body>
<style>
    iframe {
        border: none;
    }

    .code-iframe {
        overflow-x: hidden;
        overflow-y: hidden;
        margin: 0;
        padding: 0;
    }
</style>
<iframe src="./input.php" id="iframe-input" class="code-iframe"></iframe>
<iframe src="./output.php" width="50%" id="iframe-output"></iframe>
<script>
    const wsub = 60
    const hsub = 100
    document.getElementById('iframe-input').height = window.screen.height - hsub
    document.getElementById('iframe-output').height = window.screen.height - hsub
    document.getElementById('iframe-input').width = window.screen.width / 2 - wsub
    document.getElementById('iframe-output').width = window.screen.width / 2 - wsub
</script>
</body>
</html>
