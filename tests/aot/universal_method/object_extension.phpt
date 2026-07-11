--TEST--
Namespaced object extension methods use Class_method naming
--FILE--
<?php

namespace App {
    use native_types;

    class User
    {
        public function __construct(public string $name)
        {
        }

        public function existing(): string
        {
            return 'real method';
        }

        public function __call(string $method, array $args): string
        {
            return 'magic:' . $method;
        }
    }

    function User_test_method(User $user, string $suffix): string
    {
        return $user->name . $suffix . ':snake';
    }

    function User_displayName(User $user): string
    {
        return strtoupper($user->name) . ':camel';
    }

    function User_format_name(int $invalid): string
    {
        return 'invalid';
    }

    function User_formatName(User $user): string
    {
        return '[' . $user->name . ']';
    }

    function User_existing(User $user): string
    {
        return 'extension';
    }

}

namespace {
    function main(): void
    {
        $user = new \App\User('alice');
        var_dump($user->testMethod('!'));
        var_dump($user->displayName());
        var_dump($user->formatName());
        var_dump($user->existing());
        var_dump((new \App\User('bob'))->displayName());
        var_dump($user->missingMethod());

        // Dynamic method names do not participate in static extension lookup.
        $dynamicMethod = 'displayName';
        var_dump($user->$dynamicMethod());
    }
}
?>
--EXPECT--
string(12) "alice!:snake"
string(11) "ALICE:camel"
string(7) "[alice]"
string(11) "real method"
string(9) "BOB:camel"
string(19) "magic:missingMethod"
string(17) "magic:displayName"
