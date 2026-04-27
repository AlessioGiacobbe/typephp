<?php

function main(): void
{
    Swoole\Coroutine\run(function () {
        sleep(1);
    });
}