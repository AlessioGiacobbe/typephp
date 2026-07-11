--TEST--
Object extension methods require consistent names and ignore letter case
--FILE--
<?php

namespace App {
    use native_types;

    class UserService
    {
        public function __construct(public string $name)
        {
        }

        public function __call(string $method, array $args): string
        {
            return 'magic:' . $method;
        }
    }

    function UserService_displayName(UserService $service): string
    {
        return 'camel:' . $service->name;
    }

    function UserService_profile_label(UserService $service): string
    {
        return 'snake:' . $service->name;
    }

    function user_service_wrongName(UserService $service): string
    {
        return 'wrong class prefix';
    }

    function UserService_other_name(UserService $service): string
    {
        return 'wrong method suffix';
    }

    function UserService_CASECheck(UserService $service): string
    {
        return 'case-insensitive:' . $service->name;
    }
}

namespace {
    function main(): void
    {
        $service = new \App\UserService('alice');

        var_dump($service->displayName());
        var_dump($service->profile_label());
        var_dump($service->wrongName());
        var_dump($service->otherName());
        var_dump($service->casecheck());
    }
}
?>
--EXPECT--
string(11) "camel:alice"
string(11) "snake:alice"
string(15) "magic:wrongName"
string(15) "magic:otherName"
string(22) "case-insensitive:alice"
