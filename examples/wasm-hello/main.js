const storageName = 'typephp-wasi-demo-filesystem.json';
const elements = Object.fromEntries([
    'args', 'env', 'stdin', 'persistent', 'run', 'reset', 'status', 'output',
    'runtime-value', 'platform-value', 'clock-value', 'random-value', 'token-value',
    'filesystem-value', 'files-value', 'argv-value', 'env-value', 'stdin-value',
    'bigint-value', 'decimal-value', 'bigfloat-value',
].map((id) => [id, document.getElementById(id)]));

let worker = null;

function parseArguments(source) {
    const args = [];
    const pattern = /"((?:\\.|[^"\\])*)"|'((?:\\.|[^'\\])*)'|([^\s]+)/g;
    for (const match of source.matchAll(pattern)) {
        args.push((match[1] ?? match[2] ?? match[3]).replace(/\\([\\"'])/g, '$1'));
    }
    return args;
}

function parseEnvironment(source) {
    return Object.fromEntries(source.split(/\r?\n/).flatMap((line) => {
        const separator = line.indexOf('=');
        return separator > 0 ? [[line.slice(0, separator).trim(), line.slice(separator + 1)]] : [];
    }));
}

function setStatus(kind, label) {
    elements.status.className = `status ${kind}`;
    elements.status.lastChild.textContent = label;
}

function value(id, content) {
    elements[id].textContent = content === '' ? '（空）' : String(content ?? '—');
}

function renderReport(report) {
    value('runtime-value', `PHP ${report.runtime.php}`);
    value('platform-value', report.runtime.platform);
    value('clock-value', report.clock.iso8601);
    value('random-value', report.random.integer);
    value('token-value', report.random.token);
    value('filesystem-value', `第 ${report.filesystem.run} 次运行`);
    value('files-value', report.filesystem.files.join(' · '));
    value('argv-value', report.input.argv.join('  '));
    value('env-value', report.input.greeting);
    value('stdin-value', report.input.stdin);
    value('bigint-value', report.precision.bigint);
    value('decimal-value', report.precision.decimal);
    value('bigfloat-value', report.precision.bigfloat);
}

function run() {
    worker?.terminate();
    worker = new Worker(new URL('./typephp-worker.mjs', import.meta.url), { type: 'module' });
    let stdout = '';
    let stderr = '';

    elements.run.disabled = true;
    elements.output.textContent = '正在实例化 WASI 0.2 Component…';
    setStatus('running', 'Running');

    worker.onmessage = ({ data }) => {
        if (data.type === 'stdout') {
            stdout += data.data;
        } else if (data.type === 'stderr') {
            stderr += data.data;
        } else if (data.type === 'error') {
            stderr += `${data.error}\n`;
        } else if (data.type === 'exit') {
            elements.run.disabled = false;
            elements.output.textContent = [stdout, stderr].filter(Boolean).join('\n--- stderr ---\n');
            try {
                renderReport(JSON.parse(stdout));
                setStatus(data.code === 0 ? 'success' : 'error', data.code === 0 ? 'Completed' : `Exit ${data.code}`);
            } catch (error) {
                setStatus('error', `Invalid output · ${data.code}`);
                elements.output.textContent += `\n\nUI parse error: ${error.message}`;
            }
            worker.terminate();
            worker = null;
        }
    };

    worker.onerror = (event) => {
        elements.run.disabled = false;
        setStatus('error', 'Worker error');
        elements.output.textContent = event.message;
    };

    worker.postMessage({
        type: 'run',
        args: parseArguments(elements.args.value),
        env: parseEnvironment(elements.env.value),
        stdin: elements.stdin.value,
        persistent: elements.persistent.checked,
        storageName,
    });
}

async function resetStorage() {
    if (!navigator.storage?.getDirectory) {
        setStatus('error', 'OPFS unavailable');
        return;
    }
    const root = await navigator.storage.getDirectory();
    await root.removeEntry(storageName).catch((error) => {
        if (error.name !== 'NotFoundError') throw error;
    });
    setStatus('idle', 'Storage cleared');
    value('filesystem-value', '等待重新运行');
}

elements.run.addEventListener('click', run);
elements.reset.addEventListener('click', () => resetStorage().catch((error) => setStatus('error', error.message)));
run();
