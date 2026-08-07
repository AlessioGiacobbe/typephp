## 编译

```shell
./tpc --wasm test.php
```

编译成功后生成 WASI 0.2 Component `test.wasm` 和 `test.browser/` Jco 模块。WASI 0.1 不受支持。
生成的 C++ 源码默认写入 `build/`，也可以通过 `--build-dir <directory>` 指定。

## 执行

```shell
wasmtime test.wasm
```

## Chrome

```shell
cd test.browser
npm install
npm run dev
```

完整浏览器 Demo 位于仓库 `examples/wasm-hello/`，并使用 `project.yml` 构建。TypePHP 在专用 Worker 中执行；默认文件系统驻留内存，可显式启用 OPFS 快照持久化。网络 socket、进程、shell 和信号在 WASI 目标下明确不支持。
