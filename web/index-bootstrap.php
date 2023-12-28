<head>
    <meta charSet="UTF-8"/>
    <script src="https://cdn.bootcdn.net/ajax/libs/axios/1.5.0/axios.js"></script>
    <link href="https://cdn.static.runoob.com/libs/bootstrap/3.3.7/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.static.runoob.com/libs/bootstrap/3.3.7/css/bootstrap-theme.min.css" rel="stylesheet">
    <script src="https://cdn.static.runoob.com/libs/jquery/2.1.1/jquery.min.js"></script>
    <script src="https://cdn.static.runoob.com/libs/bootstrap/3.3.7/js/bootstrap.min.js"></script>
    <link href="https://cdn.bootcdn.net/ajax/libs/bootstrap-select/1.13.18/css/bootstrap-select.css" rel="stylesheet">
    <script src="https://cdn.bootcdn.net/ajax/libs/bootstrap-select/1.13.18/js/bootstrap-select.js"></script>

    <style type="text/css">
        #divCodeWrapper {
            height: calc(100vh - 200px);
            max-height: 600px;
            width: 1200px;
            overflow: hidden;
            border: 1px solid #a5a5a5;
            position: relative;
        }

        #preCode {
            height: 100%;
            width: calc(100% - 50px);
            position: absolute;
            top: 0;
            left: 50px;
            overflow: hidden;
            padding: 0;
            margin: 0;
            background: #1b1b1b;
            border: none;
        }

        #preCode code {
            padding: 15px;
            height: calc(100% - 30px);
            width: calc(100% - 30px);
            overflow-y: scroll;
            overflow-x: auto;
        }

        textarea {
            position: absolute;
            top: 0;
            padding: 15px;
            z-index: 2;
            overflow-x: auto;
            overflow-y: scroll;
            white-space: nowrap;
            background-color: rgba(0, 0, 0, 0);
            color: rgba(0, 0, 0, 0);
            caret-color: white;
            border: none;
            outline: none;
            border-left: 1px solid #383838;
        }

        #lineNumbers {
            position: absolute;
            top: 0;
            left: 0;
            text-align: right;
            height: calc(100% - 50px);
            width: 100px;
            overflow: hidden;
        }
    </style>

    <link id="theme1" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/styles/vs2015.min.css"
          rel="stylesheet"/>

    <script src="codeEditorShortcutKeys.js" type="text/javascript"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/highlight.min.js"
            type="text/javascript"></script>
</head>


<body>
<div style="width: 600px; margin: 0 auto; padding-top: 50px">
    <script>
        function postForm(form) {
            axios.post('/py2php.php', {
                code: form.code.value,
            }, {
                headers: {
                    'Content-Type': 'application/json'
                }
            }).then(response => {
                if (response.data.data.result == undefined) {
                    form.result.value = 'ERROR'
                } else {
                    form.result.value = response.data.data.result
                }
            }).catch(error => {
                console.log(error);
            });

            return false;
        }
    </script>
    <h1>phpy 工具集</h1>
    <ul class="nav nav-tabs">
        <li role="presentation" class="active"><a href="#">Python 转 PHP</a></li>
    </ul>
    <form style="margin-top: 30px" onsubmit="return postForm(this)">
        <div class="form-group">
            <label>Python 代码</label>
            <textarea class="form-control" rows="32" name="code"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">提交</button>
    </form>
</div>

</body>
