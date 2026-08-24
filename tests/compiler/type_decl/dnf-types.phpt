--TEST--
DNF types enforce intersection alternatives on parameters, returns and properties
--FILE--
<?php

interface DnfLeft
{
    public function left(): string;
}

interface DnfRight
{
    public function right(): string;
}

final class DnfBoth implements DnfLeft, DnfRight
{
    public function left(): string
    {
        return 'left';
    }

    public function right(): string
    {
        return 'right';
    }
}

final class DnfFallback
{
    public function label(): string
    {
        return 'fallback';
    }
}

final class DnfOnlyLeft implements DnfLeft
{
    public function left(): string
    {
        return 'only-left';
    }
}

function dnf_label((DnfLeft&DnfRight)|DnfFallback $value): string
{
    if ($value instanceof DnfFallback) {
        return $value->label();
    }
    return $value->left() . '+' . $value->right();
}

function dnf_identity(
    (DnfLeft&DnfRight)|DnfFallback $value,
): (DnfLeft&DnfRight)|DnfFallback {
    return $value;
}

function dnf_dynamic_return(mixed $value): (DnfLeft&DnfRight)|DnfFallback
{
    return $value;
}

final class DnfBox
{
    public (DnfLeft&DnfRight)|DnfFallback $value;

    public function __construct((DnfLeft&DnfRight)|DnfFallback $value)
    {
        $this->value = $value;
    }
}

function main(): void
{
    $both = new DnfBoth();
    $fallback = new DnfFallback();
    var_dump(dnf_label($both));
    var_dump(dnf_label($fallback));
    var_dump(dnf_identity($both) instanceof DnfBoth);

    $box = new DnfBox($fallback);
    var_dump($box->value instanceof DnfFallback);
    $box->value = $both;
    var_dump($box->value instanceof DnfBoth);

    $invalid = any(new DnfOnlyLeft());
    try {
        dnf_label($invalid);
    } catch (TypeError $error) {
        echo "parameter TypeError\n";
    }
    try {
        dnf_dynamic_return($invalid);
    } catch (TypeError $error) {
        echo "return TypeError\n";
    }
    try {
        $box->value = $invalid;
    } catch (TypeError $error) {
        echo "property TypeError\n";
    }
}
?>
--EXPECT--
string(10) "left+right"
string(8) "fallback"
bool(true)
bool(true)
bool(true)
parameter TypeError
return TypeError
property TypeError
