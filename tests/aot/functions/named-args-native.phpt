--TEST--
Native function call named arguments are reordered and validated
--FILE--
<?php
function makeUser(string $name, int $age, string $city = "Beijing", bool $vip = false): array
{
    return [
        "name" => $name,
        "age" => $age,
        "city" => $city,
        "vip" => $vip,
    ];
}

function main(): void
{
    var_dump(makeUser(age: 20, name: "Tom", vip: true));
    var_dump(makeUser("Jane", city: "Shanghai", age: 18));
}
?>
--EXPECT--
array(4) {
  ["name"]=>
  string(3) "Tom"
  ["age"]=>
  int(20)
  ["city"]=>
  string(7) "Beijing"
  ["vip"]=>
  bool(true)
}
array(4) {
  ["name"]=>
  string(4) "Jane"
  ["age"]=>
  int(18)
  ["city"]=>
  string(8) "Shanghai"
  ["vip"]=>
  bool(false)
}
