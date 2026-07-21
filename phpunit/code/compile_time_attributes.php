<?php

namespace CompileTimeAttributes;

use \Getter;
use \NotNull;
use \Printer;
use \Setter;
use \With;

class PrintableBase
{
    public int $baseId = 1;

    protected string $ignored = 'hidden';
}

#[Printer]
class User extends PrintableBase
{
    public int $id = 2;

    public string $name = '张三';

    #[Getter, Setter, With]
    private string $nickname = 'typephp';

    public function rename(#[NotNull] string $name): void
    {
        $this->name = $name;
    }
}

class CustomPrinterBase
{
    public function toString(): string
    {
        return 'custom';
    }
}

#[Printer]
class CustomPrinterChild extends CustomPrinterBase
{
    public int $value = 1;
}

#[Printer]
class LatePrinterChild extends LatePrinterBase
{
    public int $value = 1;
}

class LatePrinterBase
{
    public function toString(): string
    {
        return 'late';
    }
}

class PromotedProperties
{
    public function __construct(
        #[Getter, Setter, With]
        private int $value,
    ) {
    }
}

function requireValue(#[NotNull] int $value): int
{
    return $value;
}

function main(): void
{
    $requireName = function (#[NotNull] string $name): string {
        return $name;
    };
    $user = new User();
    $user->setNickname('php');
    $copy = $user->withNickname('cpp');
    echo $user->getNickname();
    echo $copy->getNickname();
    echo $user->toString();
    echo (new CustomPrinterChild())->toString();
    echo (new LatePrinterChild())->toString();
    echo requireValue(1);
    echo $requireName('typephp');

    $promoted = new PromotedProperties(1);
    $promoted->setValue(2);
    echo $promoted->withValue(3)->getValue();
}
