<!DOCTYPE html>

<html>

<head>
    <title>Convert Python code to PHP</title>
    <style id="styleFont">
        @import url('https://fonts.googleapis.com/css2?family=Roboto+Mono:wght@400&display=swap');

        #preCode code,
        textarea,
        #lineNumbers,
        .lineNumbers {
            font-family: "Roboto Mono", monospace;
            font-weight: 400;
            font-size: 12pt;
            line-height: 150%;
        }

    </style>
    <link href="./static/css/style.css" rel="stylesheet"/>
    <link id='theme1' href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/a11y-dark.min.css"
          rel="stylesheet"/>
    <script src="./static/js/codeEditorShortcutKeys.js" type="text/javascript"></script>
    <script src="./static/js/jquery-1.10.2.js" type="text/javascript"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/highlight.min.js"
            type="text/javascript"></script>
</head>

<body>

<h1>PHP</h1>
<?php
define('ROOT_PATH', dirname(__DIR__));
?>

<?php include ROOT_PATH . '/include/toolbar.php'; ?>

<div id="divCodeWrapper">
    <pre><code style="position: absolute; width: 100%; height: 100%; top: 0; left: 0;" class="lineNumbers"></code></pre>
    <pre><code id="lineNumbers" style="opacity: 0.5;"></code></pre>
    <pre id="preCode"><code id="codeBlock" class="language-php"></code></pre>
    <textarea id="textarea1" wrap="soft" spellcheck="false"></textarea>
</div>

<div style="margin-top: 8px">
    <button id="btn-copy" style="height: 32px; width: 90px">复制此代码</button>
</div>

<script type="text/javascript">
    const textarea1 = document.getElementById("textarea1");
    const codeBlock = document.getElementById("codeBlock");
    const lineNumbers = document.getElementById('lineNumbers');

    $('#btn-copy').click(function () {
        navigator.clipboard.writeText(textarea1.value)
        $('#btn-copy').attr('disabled', 'disabled').text('已复制代码')
    })

    $('#divCodeWrapper').css('height', (window.screen.height - 30) + 'px')

    function updateLineNumbers() {
        let lineCount = textarea1.value.split('\n').length;
        let lines = '';
        for (let i = 1; i <= lineCount; i++) {
            lines += i + '\n';
        }
        lineNumbers.innerHTML = lines;
    }

    // copy code from textarea to code block
    function updateCode() {
        let content = textarea1.value;
        $('#btn-copy').removeAttr('disabled').text('复制此代码')
        // encode the special characters
        content = content.replace(/&/g, '&amp;');
        content = content.replace(/</g, '&lt;');
        content = content.replace(/>/g, '&gt;');

        // fill the encoded text to the code
        codeBlock.innerHTML = content;
        updateLineNumbers();

        // call highlight.js to render the syntax highligtning
        highlightJS();
    }

    // syntax highlight
    function highlightJS() {
        document.querySelectorAll('pre code').forEach((el) => {
            hljs.highlightElement(el);
        });
    }

    // sync the scroll bar position between textarea and code block
    textarea1.addEventListener("scroll", () => {
        codeBlock.scrollTop = textarea1.scrollTop;
        codeBlock.scrollLeft = textarea1.scrollLeft;
        lineNumbers.scrollTop = textarea1.scrollTop;
    });

    // change theme
    document.getElementById("selectStyle").addEventListener("change", (e) => {
        document.getElementById("theme1").href = `https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/${e.target.value}`;
    });

    // change font
    function updateFont() {
        let selectFont = document.getElementById("selectFont");
        let fontName = selectFont.options[selectFont.selectedIndex].text;
        let fontNameUrl = fontName.replace(" ", "+");
        let inputFontSize = document.getElementById("inputFontSize");
        let lineHeight = document.getElementById("lineHeight");

        document.getElementById("styleFont").textContent = `
            @import url('https://fonts.googleapis.com/css2?&display=swap&family=${fontNameUrl}');
            pre, code, textarea, #lineNumbers, .lineNumbers {
                font-family: "${fontName}", monospace;
                font-size: ${inputFontSize.value}pt;
                line-height: ${lineHeight.value}%;
            }`;
    }

    // change font size
    document.getElementById("inputFontSize").addEventListener("input", () => {
        updateFont();
    });

    // change font
    document.getElementById("selectFont").addEventListener("change", () => {
        updateFont();
    });

    // change line height
    document.getElementById("lineHeight").addEventListener("input", () => {
        updateFont();
    });

    bindCodeEditorShortcutKeys(textarea1);

</script>
</body>

</html>
