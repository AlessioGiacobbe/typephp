<!DOCTYPE html>

<html>

<head>
    <title>Convert Python code to PHP</title>
    <link href="/static/css/style.css" rel="stylesheet"/>
    <link id='theme1' href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/vs2015.min.css"
          rel="stylesheet"/>
    <script src="/static/js/codeEditorShortcutKeys.js" type="text/javascript"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/highlight.min.js"
            type="text/javascript"></script>
</head>

<body>

<h1>Python 代码转 PHP</h1>
<?php
define('ROOT_PATH', dirname(__DIR__));
?>
<p style="height: 36px">
    风格:
    <?php include ROOT_PATH . '/include/style.php'; ?>
    字体:
    <?php include ROOT_PATH . '/include/font.php'; ?>
    字体尺寸:
    <input id="inputFontSize" type="number" step=".1" value="12" style="width: 40px;"/>
    编程语言:
    <?php include ROOT_PATH . '/include/lang.php'; ?>
    行高 (%):
    <input id="lineHeight" type="number" value="200" style="width: 50px;"/>
</p>

<!-- Textarea, the code editor -->
<div id="divCodeWrapper">
    <pre><code style="position: absolute; width: 100%; height: 100%; top: 0; left: 0;" class="lineNumbers"></code></pre>
    <pre><code id="lineNumbers" style="opacity: 0.5;"></code></pre>
    <pre id="preCode"><code id="codeBlock" class="language-python"></code></pre>
    <textarea id="textarea1" wrap="soft" spellcheck="false"></textarea>
</div>

<div style="margin-top: 8px">
    <button onclick="" style="height: 32px; width: 60px">转换</button>
</div>

<h2>快捷键</h2>
<ol>
    <li>
        <p><strong>Enter</strong>：换行，并保持与上一行相同的缩进</p>
    </li>
    <li>
        <p><strong>Tab</strong>/<strong>Shift</strong> + <strong>Tab</strong>：增加/减少缩进（支持多行）</p>
    </li>
    <li>
        <p><strong>Shift</strong> + <strong>Del</strong> 或 <strong>Shift</strong> + <strong>Backspace</strong>：删除整行
        </p>
    </li>
    <li>
        <p><strong>Home</strong>：将光标移到文本中的第一个非空格字符之前</p>
    </li>
</ol>

<script type="text/javascript">
    const textarea1 = document.getElementById("textarea1");
    const codeBlock = document.getElementById("codeBlock");
    const lineNumbers = document.getElementById('lineNumbers');

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

    // detect content changes in the textarea
    textarea1.addEventListener("input", () => {
        updateCode();
    });

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

    // change programming language
    document.getElementById("selectLanguage").addEventListener("change", function () {
        document.getElementById("codeBlock").className = this.value;
        highlightJS();
    });

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
