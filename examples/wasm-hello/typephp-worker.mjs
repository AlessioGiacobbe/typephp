import {
    _setStderr,
    _setStdin,
    _setStdout,
} from '@bytecodealliance/preview2-shim/cli';
import { _setFileData } from '@bytecodealliance/preview2-shim/filesystem';
import { WASIShim } from '@bytecodealliance/preview2-shim/instantiation';

const encoder = new TextEncoder();
const decoder = new TextDecoder();

function outputHandler(stream) {
    return {
        write(bytes) {
            self.postMessage({ type: stream, data: decoder.decode(bytes, { stream: true }) });
            return BigInt(bytes.byteLength);
        },
        blockingFlush() {},
    };
}

function inputHandler(text) {
    const bytes = encoder.encode(text);
    let offset = 0;
    return {
        blockingRead(length) {
            if (offset >= bytes.byteLength) {
                throw { tag: 'closed' };
            }
            const end = Math.min(offset + Number(length), bytes.byteLength);
            const chunk = bytes.slice(offset, end);
            offset = end;
            return chunk;
        },
    };
}

function encodeFileData(value) {
    return JSON.stringify(value, (_key, item) => item instanceof Uint8Array
        ? { typephpBytes: Array.from(item) }
        : item);
}

function decodeFileData(value) {
    return JSON.parse(value, (_key, item) => item && Array.isArray(item.typephpBytes)
        ? new Uint8Array(item.typephpBytes)
        : item);
}

async function openPersistentFile(name) {
    if (!navigator.storage?.getDirectory) {
        throw new Error('OPFS is not available in this browser');
    }
    const root = await navigator.storage.getDirectory();
    const handle = await root.getFileHandle(name, { create: true });
    if (typeof handle.createSyncAccessHandle !== 'function') {
        throw new Error('OPFS synchronous access requires a dedicated Worker');
    }
    return handle.createSyncAccessHandle();
}

async function loadFileData(storageName) {
    const access = await openPersistentFile(storageName);
    try {
        const size = access.getSize();
        if (size === 0) {
            return { dir: {} };
        }
        const bytes = new Uint8Array(size);
        access.read(bytes, { at: 0 });
        return decodeFileData(decoder.decode(bytes));
    } finally {
        access.close();
    }
}

async function saveFileData(storageName, fileData) {
    const access = await openPersistentFile(storageName);
    try {
        const bytes = encoder.encode(encodeFileData(fileData));
        access.truncate(0);
        access.write(bytes, { at: 0 });
        access.flush();
    } finally {
        access.close();
    }
}

self.onmessage = async ({ data }) => {
    if (data?.type !== 'run') {
        return;
    }

    let exitCode = 0;
    let fileData;
    const persistent = data.persistent === true;
    const storageName = String(data.storageName || 'typephp-wasi-filesystem.json');
    try {
        if (typeof WebAssembly.Suspending !== 'function'
            || typeof WebAssembly.promising !== 'function') {
            throw new Error('This browser does not support WebAssembly JSPI, which is required for blocking WASI I/O');
        }
        fileData = persistent ? await loadFileData(storageName) : { dir: {} };
        _setFileData(fileData);
        _setStdin(inputHandler(String(data.stdin || '')));
        _setStdout(outputHandler('stdout'));
        _setStderr(outputHandler('stderr'));

        const args = ['typephp.wasm', ...(Array.isArray(data.args) ? data.args.map(String) : [])];
        const env = data.env && typeof data.env === 'object' ? { ...data.env } : {};
        env.TYPEPHP_FETCH_URL ??= new URL('/fetch-demo.json', self.location.href).href;
        const wasi = new WASIShim({
            sandbox: {
                args,
                env,
                enableNetwork: true,
            },
        });
        const { instantiate } = await import('./generated/program.js');
        const component = await instantiate(null, wasi.getImportObject());
        await component.run.run();
    } catch (error) {
        if (error?.exitError) {
            exitCode = Number(error.code || 0);
        } else {
            self.postMessage({ type: 'error', error: error?.stack || String(error) });
            exitCode = 1;
        }
    } finally {
        if (persistent && fileData) {
            try {
                await saveFileData(storageName, fileData);
            } catch (error) {
                self.postMessage({ type: 'error', error: error?.stack || String(error) });
                exitCode = 1;
            }
        }
        self.postMessage({ type: 'exit', code: exitCode });
        self.close();
    }
};
