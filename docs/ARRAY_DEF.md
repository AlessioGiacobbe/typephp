# `#[ArrayDef]` compile-time array contracts

`#[ArrayDef]` attaches key/value type information to a property declared
exactly as `array`. It supports both Zend classes and `#[Native]` classes and
has no runtime metadata or per-read overhead.

```php
class Index
{
    #[ArrayDef(Type::String)]
    public array $names = []; // list<string>

    #[ArrayDef(Type::Int, Type::String)]
    public array $labels = []; // map<int, string>
}
```

One argument defines a list value type. Two arguments define a map key type
and value type. Map keys are restricted to `Type::Int` or `Type::String`.
`ClassName::class` is therefore valid only as a list element type or as the
second (value) argument of a map.

For direct writes whose expression types are known, the compiler either emits
the normal write unchanged or reports a fatal type error. An `any` key/value is
checked with PHPX exact-type helpers at runtime. No coercive `intval()` or
string conversion is performed.

List writes support `[]`, an existing integer index, and the exact append form
`$object->property[count($object->property)]`. Existing-index writes use
`php::safeIndex()`; negative and out-of-range indexes fail at runtime. Maps do
not support `[]` append writes.

The contract intentionally applies only to direct element assignment lowered
by TypePHP. Reads and in-place operators are unchanged. Values passed through
dynamic functions, callbacks, Reflection, `eval()`, or other ZendVM escape
paths are outside the contract and have undefined behavior from ArrayDef's
perspective.
