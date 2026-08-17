<?php

function readNativeForwardGlobal(): int
{
    global $nativeForwardGlobal;
    return $nativeForwardGlobal->value;
}
