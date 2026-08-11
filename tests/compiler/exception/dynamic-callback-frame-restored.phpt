--TEST--
Zend callback frames are restored after caught exceptions
--FILE--
<?php

final class CallbackFrameProbe
{
    public function __clone()
    {
        throw new DomainException('clone');
    }

    public function fail(): void
    {
        throw new DomainException('reflection');
    }
}

function callback_frame_is_stale(Throwable $exception, string $function): bool
{
    foreach ($exception->getTrace() as $frame) {
        if (($frame['function'] ?? '') === $function) {
            return true;
        }
    }
    return false;
}

function main(): void
{
    try {
        $copy = clone new CallbackFrameProbe();
    } catch (DomainException $exception) {
    }
    try {
        throw new RuntimeException('after clone');
    } catch (RuntimeException $exception) {
        echo 'clone=', callback_frame_is_stale($exception, '__clone') ? 'stale' : 'clean', "\n";
    }

    try {
        array_map(static function (int $value): int {
            throw new DomainException('array_map');
        }, [1]);
    } catch (DomainException $exception) {
    }
    try {
        throw new RuntimeException('after array_map');
    } catch (RuntimeException $exception) {
        echo 'array_map=', callback_frame_is_stale($exception, '{closure}') ? 'stale' : 'clean', "\n";
    }

    try {
        (new ReflectionMethod(CallbackFrameProbe::class, 'fail'))->invoke(new CallbackFrameProbe());
    } catch (DomainException $exception) {
    }
    try {
        throw new RuntimeException('after reflection');
    } catch (RuntimeException $exception) {
        echo 'reflection=', callback_frame_is_stale($exception, 'fail') ? 'stale' : 'clean', "\n";
    }
}
?>
--EXPECT--
clone=clean
array_map=clean
reflection=clean
