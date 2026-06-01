--TEST--
var convert chained on array element
--FILE--
<?php
function stream_write_test(stream $stream) {
    $stream->write('world');
}

function main()
{
    $pair = stream_socket_pair(AF_UNIX, SOCK_STREAM, 0);
    $pair[0]->toStream()->write('hello');
    var_dump($pair[1]->toStream()->read(5));

    $pair[0]->toStream()->writeTest();
    var_dump($pair[1]->toStream()->read(5));
}
?>
--EXPECT--
string(5) "hello"
string(5) "world"
