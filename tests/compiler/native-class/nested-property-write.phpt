--TEST--
Native class: direct writes support chained and expression receivers
--FILE--
<?php

#[Native]
class NativeWriteNode
{
    public int $value;
    public ?NativeWriteNode $child;

    public function __construct(int $value)
    {
        $this->value = $value;
    }
}

function makeNativeWriteNode(int $value): NativeWriteNode
{
    return new NativeWriteNode($value);
}

function main(): void
{
    $root = new NativeWriteNode(1);
    $root->child = new NativeWriteNode(2);
    $leaf = new NativeWriteNode(3);

    $root->child->child = $leaf;
    echo $root->child->child->value, "\n";

    $replacement = new NativeWriteNode(4);
    makeNativeWriteNode(5)->child = $replacement;
    echo $replacement->value, "\n";
}

?>
--EXPECT--
3
4
