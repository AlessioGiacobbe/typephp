<?php
function main()
{
    $tmpfile = tempnam(sys_get_temp_dir(), 'aot');
    stream_cast(fopen($tmpfile, 'w'))->unknownMethod();
}
