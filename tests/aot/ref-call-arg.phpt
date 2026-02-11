--TEST--
ref call arg
--FILE--
<?php
function main()
{
    $zip = new ZipArchive();
    if ($zip->open(__DIR__ . '/../../examples/test.zip') === TRUE) {
        for ($idx = 0; $s = $zip->statIndex($idx); $idx++) {
            $rs = $zip->getExternalAttributesIndex($idx, $opsys, $attr);
            var_dump($rs, $idx, $opsys, $attr);
        }
        $zip->close();
        echo "OK\n";
    }

    $str = "first=value&arr[]=foo+bar&arr[]=baz";
    parse_str($str, $output);
    echo $output['first'], PHP_EOL;  // value
    echo $output['arr'][0], PHP_EOL; // foo bar
    echo $output['arr'][1], PHP_EOL; // baz
    echo "DONE\n";
}
?>
--EXPECT--
bool(true)
int(0)
int(3)
int(1107099648)
bool(true)
int(1)
int(3)
int(2176057344)
bool(true)
int(2)
int(3)
int(2176057344)
OK
value
foo bar
baz
DONE
