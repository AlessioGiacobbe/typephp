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
    document.getElementById('iframe-input').height = window.screen.height
    document.getElementById('iframe-output').height = window.screen.height
    const sub = 60
    document.getElementById('iframe-input').width = window.screen.width / 2 - sub
    document.getElementById('iframe-output').width = window.screen.width / 2 - sub
</script>
</body>
</html>
