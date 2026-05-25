--TEST--
stream_cast() pseudo-function for stream type casting
--FILE--
<?php

function main()
{
    require __DIR__ . '/../../../src/Assert.php';

    // Test 1: stream_cast identity — on an already-inferred stream variable
    $tmpfile = tempnam(sys_get_temp_dir(), 'aot');
    $fp = fopen($tmpfile, 'w+');
    $fp->write("hello world");
    $fp->seek(0);
    $data = stream_cast($fp)->read(5);
    var_dump($data);
    $fp->close();

    // Test 2: stream_cast on array elements where type isn't known
    $pipes = [];
    $pipes[0] = fopen($tmpfile, 'w');
    $w = stream_cast($pipes[0]);
    $w->write("test data from pipe");
    $w->close();

    $pipes[1] = fopen($tmpfile, 'r');
    $r = stream_cast($pipes[1]);
    $data2 = $r->read(1024);
    var_dump($data2);
    $r->close();

    // Test 3: stream_cast in expression context (method chaining)
    $fp2 = fopen($tmpfile, 'w');
    stream_cast($fp2)->write("chain test");
    stream_cast($fp2)->close();

    $fp3 = fopen($tmpfile, 'r');
    var_dump(stream_cast($fp3)->read(10));
    stream_cast($fp3)->close();

    unlink($tmpfile);
    echo "OK\n";
}
?>
--EXPECT--
string(5) "hello"
string(19) "test data from pipe"
string(10) "chain test"
OK
