<?php

namespace PhpAot\Php;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait UniversalMethodCall
{
    protected const array UNIVERSAL_METHODS = [
        CompilerBase::TYPE_INT => [
            'add'      => ['handler' => 'calc_op', 'op' => '+', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'sub'      => ['handler' => 'calc_op', 'op' => '-', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'mul'      => ['handler' => 'calc_op', 'op' => '*', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'div'      => ['handler' => 'calc_op', 'op' => '/', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'mod'      => ['handler' => 'calc_op', 'op' => '%', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'inc'      => ['handler' => 'calc_inc', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 0, 'max_args' => 0],
            'dec'      => ['handler' => 'calc_dec', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 0, 'max_args' => 0],
            // math
            'abs'     => ['handler' => 'php_fn', 'fn' => 'abs', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 0, 'max_args' => 0],
            'ceil'    => ['handler' => 'php_fn', 'fn' => 'ceil', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'floor'   => ['handler' => 'php_fn', 'fn' => 'floor', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'round'   => ['handler' => 'php_fn', 'fn' => 'round', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 2],
            'sqrt'    => ['handler' => 'php_fn', 'fn' => 'sqrt', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'pow'     => ['handler' => 'php_fn', 'fn' => 'pow', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 1],
            'log'     => ['handler' => 'php_fn', 'fn' => 'log', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 1],
            'log10'   => ['handler' => 'php_fn', 'fn' => 'log10', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'exp'     => ['handler' => 'php_fn', 'fn' => 'exp', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'sin'     => ['handler' => 'php_fn', 'fn' => 'sin', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'cos'     => ['handler' => 'php_fn', 'fn' => 'cos', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'tan'     => ['handler' => 'php_fn', 'fn' => 'tan', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'asin'    => ['handler' => 'php_fn', 'fn' => 'asin', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'acos'    => ['handler' => 'php_fn', 'fn' => 'acos', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'atan'    => ['handler' => 'php_fn', 'fn' => 'atan', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'atan2'   => ['handler' => 'php_fn', 'fn' => 'atan2', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 1, 'max_args' => 1],
            'deg2rad' => ['handler' => 'php_fn', 'fn' => 'deg2rad', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'rad2deg' => ['handler' => 'php_fn', 'fn' => 'rad2deg', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'max'     => ['handler' => 'php_fn', 'fn' => 'max', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'min'     => ['handler' => 'php_fn', 'fn' => 'min', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
        ],
        CompilerBase::TYPE_FLOAT => [
            'add'      => ['handler' => 'calc_op', 'op' => '+', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 1, 'max_args' => 1],
            'sub'      => ['handler' => 'calc_op', 'op' => '-', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 1, 'max_args' => 1],
            'mul'      => ['handler' => 'calc_op', 'op' => '*', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 1, 'max_args' => 1],
            'div'      => ['handler' => 'calc_op', 'op' => '/', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 1, 'max_args' => 1],
            'inc'      => ['handler' => 'calc_inc', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'dec'      => ['handler' => 'calc_dec', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            // math
            'abs'     => ['handler' => 'php_fn', 'fn' => 'abs', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'ceil'    => ['handler' => 'php_fn', 'fn' => 'ceil', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'floor'   => ['handler' => 'php_fn', 'fn' => 'floor', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'round'   => ['handler' => 'php_fn', 'fn' => 'round', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 2],
            'sqrt'    => ['handler' => 'php_fn', 'fn' => 'sqrt', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'pow'     => ['handler' => 'php_fn', 'fn' => 'pow', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 1, 'max_args' => 1],
            'log'     => ['handler' => 'php_fn', 'fn' => 'log', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 1],
            'log10'   => ['handler' => 'php_fn', 'fn' => 'log10', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'exp'     => ['handler' => 'php_fn', 'fn' => 'exp', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'sin'     => ['handler' => 'php_fn', 'fn' => 'sin', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'cos'     => ['handler' => 'php_fn', 'fn' => 'cos', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'tan'     => ['handler' => 'php_fn', 'fn' => 'tan', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'asin'    => ['handler' => 'php_fn', 'fn' => 'asin', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'acos'    => ['handler' => 'php_fn', 'fn' => 'acos', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'atan'    => ['handler' => 'php_fn', 'fn' => 'atan', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'atan2'   => ['handler' => 'php_fn', 'fn' => 'atan2', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 1, 'max_args' => 1],
            'deg2rad' => ['handler' => 'php_fn', 'fn' => 'deg2rad', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'rad2deg' => ['handler' => 'php_fn', 'fn' => 'rad2deg', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 0, 'max_args' => 0],
            'max'     => ['handler' => 'php_fn', 'fn' => 'max', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 1, 'max_args' => 1],
            'min'     => ['handler' => 'php_fn', 'fn' => 'min', 'return_type' => CompilerBase::TYPE_FLOAT, 'min_args' => 1, 'max_args' => 1],
        ],
        CompilerBase::TYPE_BOOL => [
        ],
        CompilerBase::TYPE_STR => [
            // --- stdext string_methods (all use PHP standard functions) ---
            'length'                => ['handler' => 'php_fn', 'fn' => 'strlen', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 0, 'max_args' => 0],
            'isEmpty'               => ['handler' => 'direct_method', 'method' => 'empty', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            'lower'                 => ['handler' => 'php_fn', 'fn' => 'strtolower', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'upper'                 => ['handler' => 'php_fn', 'fn' => 'strtoupper', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'lowerFirst'            => ['handler' => 'php_fn', 'fn' => 'lcfirst', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'upperFirst'            => ['handler' => 'php_fn', 'fn' => 'ucfirst', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'upperWords'            => ['handler' => 'php_fn', 'fn' => 'ucwords', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'addCSlashes'           => ['handler' => 'php_fn', 'fn' => 'addcslashes', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 1],
            'addSlashes'            => ['handler' => 'php_fn', 'fn' => 'addslashes', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'chunkSplit'            => ['handler' => 'php_fn', 'fn' => 'chunk_split', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 2],
            'countChars'            => ['handler' => 'php_fn', 'fn' => 'count_chars', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 1],
            'htmlEntityDecode'      => ['handler' => 'php_fn', 'fn' => 'html_entity_decode', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 2],
            'htmlEntityEncode'      => ['handler' => 'php_fn', 'fn' => 'htmlentities', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 3],
            'htmlSpecialCharsEncode' => ['handler' => 'php_fn', 'fn' => 'htmlspecialchars', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 3],
            'htmlSpecialCharsDecode' => ['handler' => 'php_fn', 'fn' => 'htmlspecialchars_decode', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'trim'                  => ['handler' => 'php_fn', 'fn' => 'trim', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 2],
            'lTrim'                 => ['handler' => 'php_fn', 'fn' => 'ltrim', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'rTrim'                 => ['handler' => 'php_fn', 'fn' => 'rtrim', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'parseStr'              => ['handler' => 'cpp_fn', 'fn' => 'php::fn::parse_str', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 0],
            'parseUrl'              => ['handler' => 'php_fn', 'fn' => 'parse_url', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 1],
            'contains'              => ['handler' => 'php_fn', 'fn' => 'str_contains', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 1],
            'incr'                  => ['handler' => 'php_fn', 'fn' => 'str_increment', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'decr'                  => ['handler' => 'php_fn', 'fn' => 'str_decrement', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'pad'                   => ['handler' => 'php_fn', 'fn' => 'str_pad', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 3],
            'repeat'                => ['handler' => 'php_fn', 'fn' => 'str_repeat', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 1],
            'replace'               => ['handler' => 'php_fn', 'fn' => 'str_replace', 'receiver_pos' => 3, 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 2, 'max_args' => 3],
            'iReplace'              => ['handler' => 'php_fn', 'fn' => 'str_ireplace', 'receiver_pos' => 3, 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 2, 'max_args' => 3],
            'shuffle'               => ['handler' => 'php_fn', 'fn' => 'str_shuffle', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'split'                 => ['handler' => 'php_fn', 'fn' => 'explode', 'receiver_pos' => 2, 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 2],
            'startsWith'            => ['handler' => 'php_fn', 'fn' => 'str_starts_with', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 1],
            'endsWith'              => ['handler' => 'php_fn', 'fn' => 'str_ends_with', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 1],
            'wordCount'             => ['handler' => 'php_fn', 'fn' => 'str_word_count', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 2],
            'iCompare'              => ['handler' => 'php_fn', 'fn' => 'strcasecmp', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'compare'               => ['handler' => 'php_fn', 'fn' => 'strcmp', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'find'                  => ['handler' => 'php_fn', 'fn' => 'strstr', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 2],
            'iFind'                 => ['handler' => 'php_fn', 'fn' => 'stristr', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 2],
            'stripTags'             => ['handler' => 'php_fn', 'fn' => 'strip_tags', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 2],
            'stripCSlashes'         => ['handler' => 'php_fn', 'fn' => 'stripcslashes', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'stripSlashes'          => ['handler' => 'php_fn', 'fn' => 'stripslashes', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'iIndexOf'              => ['handler' => 'php_fn', 'fn' => 'stripos', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 2],
            'indexOf'               => ['handler' => 'php_fn', 'fn' => 'strpos', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 2],
            'lastIndexOf'           => ['handler' => 'php_fn', 'fn' => 'strrpos', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 2],
            'iLastIndexOf'          => ['handler' => 'php_fn', 'fn' => 'strripos', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 2],
            'lastCharIndexOf'       => ['handler' => 'php_fn', 'fn' => 'strrchr', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 1],
            'substr'                => ['handler' => 'php_fn', 'fn' => 'substr', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 2],
            'substrCompare'         => ['handler' => 'php_fn', 'fn' => 'substr_compare', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 4],
            'substrCount'           => ['handler' => 'php_fn', 'fn' => 'substr_count', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 3],
            'substrReplace'         => ['handler' => 'php_fn', 'fn' => 'substr_replace', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 3],
            'reverse'               => ['handler' => 'php_fn', 'fn' => 'strrev', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'md5'                   => ['handler' => 'php_fn', 'fn' => 'md5', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'sha1'                  => ['handler' => 'php_fn', 'fn' => 'sha1', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'crc32'                 => ['handler' => 'php_fn', 'fn' => 'crc32', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 0, 'max_args' => 0],
            'hash'                  => ['handler' => 'php_fn', 'fn' => 'hash', 'receiver_pos' => 2, 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 2],
            'hashCode'              => ['handler' => 'direct_method', 'method' => 'hashCode', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 0, 'max_args' => 0],
            'base64Decode'          => ['handler' => 'php_fn', 'fn' => 'base64_decode', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'base64Encode'          => ['handler' => 'php_fn', 'fn' => 'base64_encode', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'urlDecode'             => ['handler' => 'php_fn', 'fn' => 'urldecode', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'urlEncode'             => ['handler' => 'php_fn', 'fn' => 'urlencode', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'rawUrlEncode'          => ['handler' => 'php_fn', 'fn' => 'rawurlencode', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'rawUrlDecode'          => ['handler' => 'php_fn', 'fn' => 'rawurldecode', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'match'                 => ['handler' => 'direct_method', 'method' => 'match', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 3, 'int_cast_args' => [1, 2]],
            'matchAll'              => ['handler' => 'direct_method', 'method' => 'matchAll', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 3, 'int_cast_args' => [1, 2]],
            'isNumeric'             => ['handler' => 'php_fn', 'fn' => 'is_numeric', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            // mbstring
            'mbUpperFirst'          => ['handler' => 'php_fn', 'fn' => 'mb_ucfirst', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'mbLowerFirst'          => ['handler' => 'php_fn', 'fn' => 'mb_lcfirst', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'mbTrim'                => ['handler' => 'php_fn', 'fn' => 'mb_trim', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'mbSubstrCount'         => ['handler' => 'php_fn', 'fn' => 'mb_substr_count', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 2],
            'mbSubstr'              => ['handler' => 'php_fn', 'fn' => 'mb_substr', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 3],
            'mbUpper'               => ['handler' => 'php_fn', 'fn' => 'mb_strtoupper', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'mbLower'               => ['handler' => 'php_fn', 'fn' => 'mb_strtolower', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'mbFind'                => ['handler' => 'php_fn', 'fn' => 'mb_strstr', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 3],
            'mbIndexOf'             => ['handler' => 'php_fn', 'fn' => 'mb_strpos', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 3],
            'mbLastIndexOf'         => ['handler' => 'php_fn', 'fn' => 'mb_strrpos', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 3],
            'mbILastIndexOf'        => ['handler' => 'php_fn', 'fn' => 'mb_strripos', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 3],
            'mbLastCharIndexOf'     => ['handler' => 'php_fn', 'fn' => 'mb_strrchr', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 3],
            'mbILastCharIndex'      => ['handler' => 'php_fn', 'fn' => 'mb_strrichr', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 3],
            'mbLength'              => ['handler' => 'php_fn', 'fn' => 'mb_strlen', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 0, 'max_args' => 1],
            'mbIFind'               => ['handler' => 'php_fn', 'fn' => 'mb_stristr', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 3],
            'mbIIndexOf'            => ['handler' => 'php_fn', 'fn' => 'mb_stripos', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 3],
            'mbCut'                 => ['handler' => 'php_fn', 'fn' => 'mb_strcut', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 3],
            'mbRTrim'               => ['handler' => 'php_fn', 'fn' => 'mb_rtrim', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'mbLTrim'               => ['handler' => 'php_fn', 'fn' => 'mb_ltrim', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            'mbDetectEncoding'      => ['handler' => 'php_fn', 'fn' => 'mb_detect_encoding', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 2],
            'mbConvertEncoding'     => ['handler' => 'php_fn', 'fn' => 'mb_convert_encoding', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 2],
            'mbConvertCase'         => ['handler' => 'php_fn', 'fn' => 'mb_convert_case', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 2],
            // serialize
            'unserialize'           => ['handler' => 'php_fn', 'fn' => 'unserialize', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 0],
            'unmarshal'             => ['handler' => 'php_fn', 'fn' => 'unserialize', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 0],
            'jsonDecode'            => ['handler' => 'php_fn', 'fn' => 'json_decode', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 2, 'const_args' => [1 => 'true']],
            'jsonDecodeToObject'    => ['handler' => 'php_fn', 'fn' => 'json_decode', 'return_type' => CompilerBase::TYPE_OBJECT, 'min_args' => 0, 'max_args' => 2, 'const_args' => [1 => 'false']],
            // phpx C++ methods (no PHP function equivalent)
            'equals'                => ['handler' => 'direct_method', 'method' => 'equals', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 2],
        ],
        CompilerBase::TYPE_ARRAY => [
            // --- stdext array_methods (all use PHP standard functions) ---
            'all'               => ['handler' => 'php_fn', 'fn' => 'array_all', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 1],
            'any'               => ['handler' => 'php_fn', 'fn' => 'array_any', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 1],
            'changeKeyCase'     => ['handler' => 'php_fn', 'fn' => 'array_change_key_case', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 1],
            'chunk'             => ['handler' => 'php_fn', 'fn' => 'array_chunk', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 2],
            'column'            => ['handler' => 'php_fn', 'fn' => 'array_column', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 2],
            'countValues'       => ['handler' => 'php_fn', 'fn' => 'array_count_values', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 0],
            'diff'              => ['handler' => 'php_fn', 'fn' => 'array_diff', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => -1],
            'diffAssoc'         => ['handler' => 'php_fn', 'fn' => 'array_diff_assoc', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => -1],
            'diffKey'           => ['handler' => 'php_fn', 'fn' => 'array_diff_key', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => -1],
            'filter'            => ['handler' => 'php_fn', 'fn' => 'array_filter', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 2],
            'find'              => ['handler' => 'php_fn', 'fn' => 'array_find', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 1],
            'flip'              => ['handler' => 'php_fn', 'fn' => 'array_flip', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 0],
            'intersect'         => ['handler' => 'php_fn', 'fn' => 'array_intersect', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => -1],
            'intersectAssoc'    => ['handler' => 'php_fn', 'fn' => 'array_intersect_assoc', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => -1],
            'isList'            => ['handler' => 'php_fn', 'fn' => 'array_is_list', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            'keyExists'         => ['handler' => 'php_fn', 'fn' => 'array_key_exists', 'receiver_pos' => 2, 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 1],
            'keyFirst'          => ['handler' => 'php_fn', 'fn' => 'array_key_first', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 0],
            'keyLast'           => ['handler' => 'php_fn', 'fn' => 'array_key_last', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 0],
            'keys'              => ['handler' => 'php_fn', 'fn' => 'array_keys', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 2],
            'map'               => ['handler' => 'php_fn', 'fn' => 'array_map', 'receiver_pos' => 2, 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => -1],
            'pad'               => ['handler' => 'php_fn', 'fn' => 'array_pad', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 2, 'max_args' => 2],
            'product'           => ['handler' => 'php_fn', 'fn' => 'array_product', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 0],
            'rand'              => ['handler' => 'php_fn', 'fn' => 'array_rand', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 1],
            'reduce'            => ['handler' => 'php_fn', 'fn' => 'array_reduce', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 2],
            'replace'           => ['handler' => 'php_fn', 'fn' => 'array_replace', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => -1],
            'reverse'           => ['handler' => 'php_fn', 'fn' => 'array_reverse', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 1],
            'search'            => ['handler' => 'php_fn', 'fn' => 'array_search', 'receiver_pos' => 2, 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 2],
            'slice'             => ['handler' => 'php_fn', 'fn' => 'array_slice', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 3],
            'sum'               => ['handler' => 'php_fn', 'fn' => 'array_sum', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 0],
            'unique'            => ['handler' => 'php_fn', 'fn' => 'array_unique', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 1],
            'values'            => ['handler' => 'php_fn', 'fn' => 'array_values', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 0],
            'count'             => ['handler' => 'php_fn', 'fn' => 'count', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 0, 'max_args' => 0],
            'merge'             => ['handler' => 'php_fn', 'fn' => 'array_merge', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => -1],
            'contains'          => ['handler' => 'php_fn', 'fn' => 'in_array', 'receiver_pos' => 2, 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 2],
            'join'              => ['handler' => 'php_fn', 'fn' => 'implode', 'receiver_pos' => 2, 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 1],
            'isEmpty'           => ['handler' => 'direct_method', 'method' => 'empty', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            // mutating via PHP reference functions
            'sort'              => ['handler' => 'php_fn_ref', 'fn' => 'sort', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 1],
            'pop'               => ['handler' => 'php_fn_ref', 'fn' => 'array_pop', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 0],
            'push'              => ['handler' => 'php_fn_ref', 'fn' => 'array_push', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => -1],
            'shift'             => ['handler' => 'php_fn_ref', 'fn' => 'array_shift', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 0, 'max_args' => 0],
            'unshift'           => ['handler' => 'php_fn_ref', 'fn' => 'array_unshift', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => -1],
            'splice'            => ['handler' => 'php_fn_ref', 'fn' => 'array_splice', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 3],
            'walk'              => ['handler' => 'php_fn_ref', 'fn' => 'array_walk', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 2],
            'sortDesc'          => ['handler' => 'php_fn_ref', 'fn' => 'rsort', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 1],
            'keySort'           => ['handler' => 'php_fn_ref', 'fn' => 'ksort', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 1],
            'valueSort'         => ['handler' => 'php_fn_ref', 'fn' => 'asort', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 1],
            'combine'           => ['handler' => 'php_fn', 'fn' => 'array_combine', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 1],
            'fillKeys'          => ['handler' => 'php_fn', 'fn' => 'array_fill_keys', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 1],
            'replaceStr'        => ['handler' => 'php_fn', 'fn' => 'str_replace', 'receiver_pos' => 3, 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 2],
            'iReplaceStr'       => ['handler' => 'php_fn', 'fn' => 'str_ireplace', 'receiver_pos' => 3, 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 2],
            // serialize
            'serialize'         => ['handler' => 'php_fn', 'fn' => 'serialize', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'marshal'           => ['handler' => 'php_fn', 'fn' => 'serialize', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'jsonEncode'        => ['handler' => 'php_fn', 'fn' => 'json_encode', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 2],
            // phpx C++ methods (no PHP function equivalent)
            'set'               => ['handler' => 'direct_method_mutate', 'method' => 'set', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 2, 'max_args' => 2],
            'get'               => ['handler' => 'direct_method', 'method' => 'get', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 1],
            'del'               => ['handler' => 'direct_method_mutate', 'method' => 'del', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 1],
            'clean'             => ['handler' => 'direct_method_mutate', 'method' => 'clean', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 0],
        ],
        CompilerBase::TYPE_STREAM => [
            // --- stdext stream_methods ---
            'write'             => ['handler' => 'php_fn', 'fn' => 'fwrite', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 2],
            'read'              => ['handler' => 'php_fn', 'fn' => 'fread', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 1],
            'close'             => ['handler' => 'php_fn', 'fn' => 'fclose', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            'dataSync'          => ['handler' => 'php_fn', 'fn' => 'fdatasync', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            'sync'              => ['handler' => 'php_fn', 'fn' => 'fsync', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            'truncate'          => ['handler' => 'php_fn', 'fn' => 'ftruncate', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 1],
            'stat'              => ['handler' => 'php_fn', 'fn' => 'fstat', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 0],
            'seek'              => ['handler' => 'php_fn', 'fn' => 'fseek', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 2],
            'tell'              => ['handler' => 'php_fn', 'fn' => 'ftell', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 0, 'max_args' => 0],
            'lock'              => ['handler' => 'php_fn', 'fn' => 'flock', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 2],
            'eof'               => ['handler' => 'php_fn', 'fn' => 'feof', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            'getChar'           => ['handler' => 'php_fn', 'fn' => 'fgetc', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 0],
            'getLine'           => ['handler' => 'php_fn', 'fn' => 'fgets', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 1],
            // --- stream_* functions ---
            'getContents'       => ['handler' => 'php_fn', 'fn' => 'stream_get_contents', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 2],
            'getMetaData'       => ['handler' => 'php_fn', 'fn' => 'stream_get_meta_data', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 0, 'max_args' => 0],
            'isLocal'           => ['handler' => 'php_fn', 'fn' => 'stream_is_local', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            'isTTY'             => ['handler' => 'php_fn', 'fn' => 'stream_isatty', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            'setBlocking'       => ['handler' => 'php_fn', 'fn' => 'stream_set_blocking', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 1],
            'setChunkSize'      => ['handler' => 'php_fn', 'fn' => 'stream_set_chunk_size', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'setReadBuffer'     => ['handler' => 'php_fn', 'fn' => 'stream_set_read_buffer', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'setTimeout'        => ['handler' => 'php_fn', 'fn' => 'stream_set_timeout', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 2],
            'setWriteBuffer'    => ['handler' => 'php_fn', 'fn' => 'stream_set_write_buffer', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'supportsLock'      => ['handler' => 'php_fn', 'fn' => 'stream_supports_lock', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 0, 'max_args' => 0],
            'copy'              => ['handler' => 'php_fn', 'fn' => 'stream_copy_to_stream', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 3],
            // --- stream_socket_* functions ---
            'accept'            => ['handler' => 'php_fn', 'fn' => 'stream_socket_accept', 'return_type' => CompilerBase::TYPE_STREAM, 'min_args' => 0, 'max_args' => 1],
            'enableCrypto'      => ['handler' => 'php_fn', 'fn' => 'stream_socket_enable_crypto', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 3],
            'getSocketName'     => ['handler' => 'php_fn', 'fn' => 'stream_socket_get_name', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 1],
            'recvFrom'          => ['handler' => 'php_fn', 'fn' => 'stream_socket_recvfrom', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 1, 'max_args' => 2],
            'sendTo'            => ['handler' => 'php_fn', 'fn' => 'stream_socket_sendto', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 3],
            'shutdown'          => ['handler' => 'php_fn', 'fn' => 'stream_socket_shutdown', 'return_type' => CompilerBase::TYPE_BOOL, 'min_args' => 1, 'max_args' => 1],
            'getRecord'         => ['handler' => 'php_fn', 'fn' => 'stream_get_line', 'return_type' => CompilerBase::TYPE_STR, 'min_args' => 0, 'max_args' => 2],
            // --- stream filters ---
            'appendFilter'      => ['handler' => 'php_fn', 'fn' => 'stream_filter_append', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 3],
            'prependFilter'     => ['handler' => 'php_fn', 'fn' => 'stream_filter_prepend', 'return_type' => CompilerBase::TYPE_VAR, 'min_args' => 1, 'max_args' => 3],
        ],
        CompilerBase::TYPE_BIGINT => [
            'add'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::add', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'sub'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::sub', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'mul'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::mul', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'div'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::div', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'mod'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::mod', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'pow'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::pow', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'neg'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::neg', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 0, 'max_args' => 0],
            'cmp'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::cmp', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'abs'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::abs', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 0, 'max_args' => 0],
            'gcd'          => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::gcd', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'divmod'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::divmod', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 1],
            'powmod'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::powmod', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 2, 'max_args' => 2],
            'sqrt'         => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::sqrt', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 0, 'max_args' => 0],
            'bitAnd'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitAnd', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'bitOr'        => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitOr', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'bitXor'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitXor', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'bitNot'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitNot', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 0, 'max_args' => 0],
            'testBit'        => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::testBit', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'popCount'       => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::popCount', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 0, 'max_args' => 0],
            'bitShiftLeft'   => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitShiftLeft', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
            'bitShiftRight'  => ['handler' => 'cpp_fn', 'fn' => 'php::BigInt::bitShiftRight', 'return_type' => CompilerBase::TYPE_BIGINT, 'min_args' => 1, 'max_args' => 1],
        ],
        CompilerBase::TYPE_DECIMAL => [
            'add'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::add', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'sub'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::sub', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'mul'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::mul', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'div'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::div', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'mod'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::mod', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'pow'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::pow', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 1, 'max_args' => 1],
            'neg'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::neg', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 0, 'max_args' => 0],
            'cmp'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::cmp', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'abs'      => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::abs', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 0, 'max_args' => 0],
            'divmod'   => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::divmod', 'return_type' => CompilerBase::TYPE_ARRAY, 'min_args' => 1, 'max_args' => 1],
            'powmod'   => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::powmod', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 2, 'max_args' => 2],
            'sqrt'     => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::sqrt', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 0, 'max_args' => 0],
            'floor'    => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::floor', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 0, 'max_args' => 0],
            'ceil'     => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::ceil', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 0, 'max_args' => 0],
            'round'    => ['handler' => 'cpp_fn', 'fn' => 'php::Decimal::round', 'return_type' => CompilerBase::TYPE_DECIMAL, 'min_args' => 0, 'max_args' => 1],
        ],
        CompilerBase::TYPE_BIGFLOAT => [
            'add'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::add', 'return_type' => CompilerBase::TYPE_BIGFLOAT, 'min_args' => 1, 'max_args' => 1],
            'sub'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::sub', 'return_type' => CompilerBase::TYPE_BIGFLOAT, 'min_args' => 1, 'max_args' => 1],
            'mul'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::mul', 'return_type' => CompilerBase::TYPE_BIGFLOAT, 'min_args' => 1, 'max_args' => 1],
            'div'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::div', 'return_type' => CompilerBase::TYPE_BIGFLOAT, 'min_args' => 1, 'max_args' => 1],
            'neg'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::neg', 'return_type' => CompilerBase::TYPE_BIGFLOAT, 'min_args' => 0, 'max_args' => 0],
            'cmp'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::cmp', 'return_type' => CompilerBase::TYPE_INT, 'min_args' => 1, 'max_args' => 1],
            'abs'      => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::abs', 'return_type' => CompilerBase::TYPE_BIGFLOAT, 'min_args' => 0, 'max_args' => 0],
            'sqrt'     => ['handler' => 'cpp_fn', 'fn' => 'php::BigFloat::sqrt', 'return_type' => CompilerBase::TYPE_BIGFLOAT, 'min_args' => 0, 'max_args' => 0],
        ],
    ];

    protected const array MUTATING_HANDLERS = ['direct_method_mutate', 'php_fn_ref'];

    protected function detectUniversalMethodReturnType(string $type, string $method): ?string
    {
        $builtin = self::UNIVERSAL_METHODS[$type][$method]['return_type'] ?? null;
        if ($builtin !== null) {
            return $builtin;
        }
        $ext = $this->findExtensionMethod($type, $method);
        return $ext ? $ext['return_type'] : null;
    }

    protected const array TYPE_EXTENSION_PREFIX = [
        CompilerBase::TYPE_INT    => 'int',
        CompilerBase::TYPE_FLOAT  => 'float',
        CompilerBase::TYPE_BOOL   => 'bool',
        CompilerBase::TYPE_STR    => 'str',
        CompilerBase::TYPE_ARRAY  => 'array',
        CompilerBase::TYPE_STREAM => 'stream',
        CompilerBase::TYPE_BIGINT => 'bigint',
        CompilerBase::TYPE_DECIMAL => 'decimal',
        CompilerBase::TYPE_BIGFLOAT => 'bigfloat',
        CompilerBase::TYPE_BOX => 'box',
    ];

    protected function camelToSnake(string $name): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $name));
    }

    protected const array TO_CONVERT_FN = [
        CompilerBase::TYPE_BIGINT   => ['toInt' => 'php::BigInt::toInt', 'toFloat' => 'php::BigInt::toFloat', 'toString' => 'php::BigInt::toString'],
        CompilerBase::TYPE_BIGFLOAT => ['toInt' => 'php::BigFloat::toInt', 'toFloat' => 'php::BigFloat::toFloat', 'toString' => 'php::BigFloat::toString'],
        CompilerBase::TYPE_DECIMAL  => ['toInt' => 'php::Decimal::toInt', 'toFloat' => 'php::Decimal::toFloat', 'toString' => 'php::Decimal::toString'],
    ];

    /**
     * Generate C++ code for a to* keyword conversion call.
     */
    protected function genToConvertCall(string $receiver, string $method, string $receiverType = ''): string
    {
        if ($receiverType !== '' && isset(self::TO_CONVERT_FN[$receiverType][$method])) {
            return self::TO_CONVERT_FN[$receiverType][$method] . '(' . $receiver . ')';
        }
        return match ($method) {
            'toInt'       => 'php::toInt(' . $receiver . ')',
            'toFloat'     => 'php::toFloat(' . $receiver . ')',
            'toString'    => 'php::toString(' . $receiver . ')',
            'toBool'      => 'php::toBool(' . $receiver . ')',
            'toArray'     => 'php::toArray(' . $receiver . ')',
            'toStream'    => 'php::toStream(' . $receiver . ')',
            'toBigInt'    => 'php::BigInt::newInstance(' . $receiver . ')',
            'toBigFloat'  => 'php::BigFloat::newInstance(' . $receiver . ')',
            'toDecimal'   => 'php::Decimal::newInstance(' . $receiver . ')',
            default       => $receiver,
        };
    }

    protected function findUniversalMethodAnyType(string $type, string $method): ?array
    {
        $builtin = self::UNIVERSAL_METHODS[$type][$method] ?? null;
        if ($builtin !== null) {
            return $builtin;
        }
        return $this->findExtensionMethod($type, $method);
    }

    /**
     * Look up an extension function for the given type+method.
     * Extension functions follow the naming convention: {typePrefix}_{snake_case_method}
     *
     * Checks compiled user-defined functions first, then falls back to PHP internal
     * functions using reflection to resolve parameter counts and return types.
     */
    protected function findExtensionMethod(string $type, string $method): ?array
    {
        $prefix = self::TYPE_EXTENSION_PREFIX[$type] ?? null;
        if ($prefix === null) {
            return null;
        }

        $snakeMethod = $this->camelToSnake($method);
        $funcName = $prefix . '_' . $snakeMethod;

        $resolvedName = $this->resolveExtensionFunctionName($funcName);
        if ($resolvedName !== null) {
            $funcDef = $this->getFunction($resolvedName);
            if (!$this->validateExtensionFirstParam($type, $funcDef)) {
                return null;
            }
            return [
                'handler'      => 'php_fn',
                'fn'           => $resolvedName,
                'receiver_pos' => 1,
                'return_type'  => $funcDef->returnType,
                'min_args'     => 0,
                'max_args'     => -1,
            ];
        }

        if ($this->isInternalFunction($funcName)) {
            return $this->buildInternalExtensionMethod($type, $funcName);
        }

        return null;
    }

    /**
     * Look up a keyword extension method: function __snake_case in root namespace
     * whose first parameter is mixed/any. Callable as $receiver->camelCase() on any type.
     */
    protected function findKeywordExtensionMethod(string $method): ?array
    {
        $snakeMethod = $this->camelToSnake($method);
        $funcName = '__' . $snakeMethod;

        if (!$this->hasFunction($funcName)) {
            return null;
        }

        $funcDef = $this->getFunction($funcName);

        // Must be in root namespace
        if ($funcDef->namespace !== '') {
            return null;
        }

        // First param must be mixed/any (TYPE_VAR)
        if (empty($funcDef->argInfoList)) {
            return null;
        }
        $firstParam = $funcDef->argInfoList[0];
        if ($firstParam->type !== CompilerBase::TYPE_VAR) {
            return null;
        }

        $totalParams = count($funcDef->argInfoList);
        $minArgs = max(0, $funcDef->argCountRequired - 1);
        $maxArgs = $totalParams - 1;
        if ($funcDef->hasVariadicArg()) {
            $maxArgs = -1;
        }

        return [
            'handler'      => 'php_fn',
            'fn'           => $funcName,
            'receiver_pos' => 1,
            'return_type'  => $funcDef->returnType,
            'min_args'     => $minArgs,
            'max_args'     => $maxArgs,
        ];
    }

    /**
     * Unified keyword method lookup (to* builtins + __ extension methods).
     * Returns the return type string, or null if the method is not a keyword method.
     */
    protected function findKeywordMethod(string $method): ?string
    {
        if (isset(CompilerBase::KEYWORD_METHOD_MAP[$method])) {
            return CompilerBase::KEYWORD_METHOD_MAP[$method];
        }
        $kwExt = $this->findKeywordExtensionMethod($method);
        return $kwExt ? $kwExt['return_type'] : null;
    }

    protected function buildInternalExtensionMethod(string $type, string $funcName): ?array
    {
        $ref = Reflection::getFunction($funcName);
        if ($ref === null) {
            return null;
        }

        $totalParams = $ref->getNumberOfParameters();
        if ($totalParams < 1) {
            return null;
        }

        if (!$this->validateInternalExtensionFirstParam($type, $funcName)) {
            return null;
        }

        $phpType = Reflection::getFunctionReturnType($funcName);
        $returnType = $phpType ? ($this->zendTypeMap[$phpType] ?? CompilerBase::TYPE_VAR) : CompilerBase::TYPE_VAR;

        $requiredParams = $ref->getNumberOfRequiredParameters();
        $minArgs = max(0, $requiredParams - 1);
        $maxArgs = max(0, $totalParams - 1);

        if ($totalParams > 0) {
            $lastParam = Reflection::getFunctionParameter($funcName, $totalParams - 1);
            if ($lastParam !== null && $lastParam->isVariadic()) {
                $maxArgs = -1;
            }
        }

        return [
            'handler'      => 'php_fn',
            'fn'           => $funcName,
            'receiver_pos' => 1,
            'return_type'  => $returnType,
            'min_args'     => $minArgs,
            'max_args'     => $maxArgs,
        ];
    }

    protected function validateExtensionFirstParam(string $type, \PhpAot\Php\Entity\FunctionDef $funcDef): bool
    {
        if (empty($funcDef->argInfoList)) {
            return false;
        }
        $firstParam = $funcDef->argInfoList[0];
        // Stream/Box are PHP pseudo-types; their params may be typed or untyped
        if ($type === self::TYPE_STREAM || $type === self::TYPE_BOX) {
            return $firstParam->byRef || $firstParam->type === self::TYPE_VAR || $firstParam->type === self::TYPE_REF || $firstParam->type === $type;
        }
        if ($firstParam->byRef) {
            return $type === self::TYPE_ARRAY;
        }
        $paramType = $firstParam->type;
        if ($paramType === self::TYPE_VAR) {
            return false;
        }
        return $paramType === $type;
    }

    protected function validateInternalExtensionFirstParam(string $type, string $funcName): bool
    {
        $param = Reflection::getFunctionParameter($funcName, 0);
        if ($param === null) {
            return false;
        }
        // Stream pseudo-type: accept untyped or by-reference first params
        if ($type === self::TYPE_STREAM) {
            return true;
        }
        if ($param->isPassedByReference()) {
            return $type === self::TYPE_ARRAY;
        }
        $paramType = $param->getType();
        if ($paramType === null) {
            return false;
        }
        if ($paramType instanceof \ReflectionNamedType) {
            $phpName = $paramType->getName();
            $compilerType = $this->zendTypeMap[$phpName] ?? null;
            return $compilerType === $type;
        }
        return false;
    }

    protected function resolveExtensionFunctionName(string $funcName): ?string
    {
        if ($this->hasFunction($funcName)) {
            return $funcName;
        }
        return null;
    }

    /**
     * @param string $receiver C++ expression for the receiver
     * @param bool   $isVar    Whether the receiver is a variable (allows mutating methods)
     */
    protected function parseUniversalMethodCall(Node\Expr\MethodCall $expr, string $receiver, string $method, array $def, bool $isVar = true): ?string
    {
        $this->validateUniversalMethodArgs($expr, $method, $def, $isVar);

        return match ($def['handler']) {
            'calc_op'              => $this->genUniversalCalcOp($receiver, $def['op'], $expr->args),
            'calc_inc'             => '(' . $receiver . ' + 1)',
            'calc_dec'             => '(' . $receiver . ' - 1)',
            'direct_method'        => $this->genUniversalDirectMethod($receiver, $def['method'], $expr->args, $def['int_cast_args'] ?? []),
            'direct_method_mutate' => $this->genUniversalMutatingMethod($receiver, $def['method'], $expr->args),
            'convert_fn'           => $this->genUniversalConvertFn($receiver, $def['fn']),
            'php_fn'               => $this->genUniversalPhpFn($receiver, $def['fn'], $expr->args, $def['receiver_pos'] ?? 0, $def['const_args'] ?? []),
            'php_fn_ref'           => $this->genUniversalPhpFnRef($receiver, $def['fn'], $expr->args, $def['return_type']),
            'cpp_fn'               => $this->genUniversalCppFn($receiver, $def['fn'], $expr->args, $def['receiver_pos'] ?? 0),
            default                => null,
        };
    }

    /**
     * Generate a stream method call with null guard.
     * Stream resources are nullable — throws Error if the receiver is null.
     */
    protected function genStreamNullGuard(Node\Expr\MethodCall $expr, string $receiver, string $method, array $def): string
    {
        $this->validateUniversalMethodArgs($expr, $method, $def, false);

        // Evaluate receiver into temp var to avoid double evaluation
        $streamVar = $this->addTmpVar(self::TYPE_VAR);
        $this->context->beforeStmtLines[] = $streamVar . ' = ' . $receiver . ';';

        $methodCall = match ($def['handler']) {
            'php_fn'        => $this->genUniversalPhpFn('php::toStream(' . $streamVar . ')', $def['fn'], $expr->args, $def['receiver_pos'] ?? 0, $def['const_args'] ?? []),
            'direct_method' => $this->genUniversalDirectMethod('php::toStream(' . $streamVar . ')', $def['method'], $expr->args, $def['int_cast_args'] ?? []),
            'cpp_fn'        => $this->genUniversalCppFn('php::toStream(' . $streamVar . ')', $def['fn'], $expr->args, $def['receiver_pos'] ?? 0),
            default         => null,
        };

        $tmpVar = $this->addTmpVar(self::TYPE_VAR);
        $this->context->beforeStmtLines[] = "{$tmpVar} = {$methodCall};";
        return $tmpVar;
    }

    /**
     * Wrap a non-variable receiver expression in the appropriate type conversion
     * so that direct C++ method calls (e.g. .get(), .set()) work on the correct type.
     */
    protected function wrapUniversalReceiver(string $type, string $expr): string
    {
        $convFns = [
            self::TYPE_ARRAY  => 'toArray',
            self::TYPE_STR    => 'toString',
            self::TYPE_INT    => 'toInt',
            self::TYPE_FLOAT  => 'toFloat',
            self::TYPE_BOOL   => 'toBool',
        ];
        if (isset($convFns[$type])) {
            return 'php::' . $convFns[$type] . '(' . $expr . ')';
        }
        return $expr;
    }

    protected function validateUniversalMethodArgs(Node\Expr\MethodCall $expr, string $method, array $def, bool $isVar): void
    {
        $argCount = count($expr->args);
        $maxArgs = $def['max_args'];
        if ($maxArgs === -1) {
            $maxArgs = PHP_INT_MAX;
        }
        if ($argCount < $def['min_args'] || $argCount > $maxArgs) {
            $expected = $def['min_args'] === $def['max_args'] ? "exactly {$def['min_args']}" : "{$def['min_args']} to {$def['max_args']}";
            $this->fatalError($expr, "Method `{$method}()` expects {$expected} argument(s), {$argCount} given");
        }

        if (!$isVar && in_array($def['handler'], self::MUTATING_HANDLERS, true)) {
            $this->fatalError($expr, 'Cannot call mutating method `' . $method . '()` on a non-variable expression');
        }
    }

    protected function genUniversalCalcOp(string $object, string $op, array $args): string
    {
        $argExpr = $this->parseExpr($args[0]->value);
        return '(' . $object . ' ' . $op . ' ' . $argExpr . ')';
    }

    protected function genUniversalDirectMethod(string $object, string $cppMethod, array $args, array $intCastArgs = []): string
    {
        if (empty($args)) {
            return $object . '.' . $cppMethod . '()';
        }
        $argExprs = [];
        foreach ($args as $i => $arg) {
            $expr = $this->parseExpr($arg->value);
            if (in_array($i, $intCastArgs, true)) {
                $expr = 'php::toInt(' . $expr . ')';
            }
            $argExprs[] = $expr;
        }
        return $object . '.' . $cppMethod . '(' . implode(', ', $argExprs) . ')';
    }

    protected function genUniversalMutatingMethod(string $object, string $cppMethod, array $args): string
    {
        $call = $this->genUniversalDirectMethod($object, $cppMethod, $args);
        return '(' . $call . ', ' . $object . ')';
    }

    protected function genUniversalConvertFn(string $receiver, string $fn): string
    {
        return 'php::' . $fn . '(' . $receiver . ')';
    }

    private function mergeConstArgs(array $argExprs, array $constArgs): array
    {
        if (!$constArgs) {
            return $argExprs;
        }
        $maxPos = max(array_keys($constArgs));
        $totalSlots = max(count($argExprs) + count($constArgs), $maxPos + 1);
        $merged = [];
        $regIdx = 0;
        for ($i = 0; $i < $totalSlots; $i++) {
            if (isset($constArgs[$i])) {
                $merged[] = $constArgs[$i];
            } else {
                $merged[] = $argExprs[$regIdx++];
            }
        }
        return $merged;
    }

    /**
     * Try to generate a direct C++ call for a php_fn method using the FuncCallOptimizer's
     * type-aware argument resolution. Returns null if the function is not optimizable.
     */
    private function tryOptimizePhpFn(string $receiver, string $phpFunc, array $args, int $receiverPos, array $constArgs): ?string
    {
        $config = $this->getFuncCallConfig()[$phpFunc] ?? null;
        if ($config === null) {
            return null;
        }

        $targetName = $phpFunc;
        if (is_string($config)) {
            $targetName = $config;
            $config = $this->getFuncCallConfig()[$targetName] ?? [];
        }

        // Skip entries that need FuncCall AST nodes
        if (isset($config['handler']) || isset($config['bigDispatch']) || isset($config['conversion'])) {
            return null;
        }

        $refInfo = $this->getArgReflectionInfo($targetName);
        if (!empty($config['variadic']) || ($refInfo['variadic'] ?? false)) {
            return null;
        }

        $target = $config['target'] ?? null;
        if ($target === null) {
            $target = 'php::fn::' . $targetName;
        } elseif (!str_starts_with($target, 'php::')) {
            $target = 'php::fn::' . $target;
        }

        $argTypeStr = $config['args'] ?? ($refInfo['args'] ?? '');
        $defaults = $config['defaults'] ?? [];

        $rawArgs = $this->mergeConstArgs(
            $this->buildReceiverArgs($receiver, $args, $receiverPos),
            $constArgs
        );

        if ($argTypeStr !== '') {
            $types = explode('_', $argTypeStr);
            foreach ($types as $i => $type) {
                if (!isset($rawArgs[$i])) {
                    if (($type[0] ?? '') === self::ARG_OPTIONAL && isset($defaults[$i])) {
                        continue;
                    }
                    return null;
                }
                $rawArgs[$i] = $this->applyArgConversion($rawArgs[$i], $type);
            }
        }

        return $target . '(' . implode(', ', $rawArgs) . ')';
    }

    /**
     * @param int $receiverPos Position of the receiver in the final argument list.
     *  0 (default) = receiver first. Non-zero values are 1-indexed (1 = before 1st user arg,
     *  2 = before 2nd, etc.). e.g. hash(algo, data, binary) needs receiver as data → receiverPos=2.
     */
    protected function genUniversalPhpFn(string $receiver, string $phpFunc, array $args, int $receiverPos = 0, array $constArgs = []): string
    {
        // Try direct C++ call with type conversions first
        $optimized = $this->tryOptimizePhpFn($receiver, $phpFunc, $args, $receiverPos, $constArgs);
        if ($optimized !== null) {
            return $optimized;
        }

        // Fall back to dynamic php::call()
        $argExprs = $this->mergeConstArgs(
            $this->buildReceiverArgs($receiver, $args, $receiverPos),
            $constArgs
        );

        return 'php::call(' . $this->getFuncPtr($phpFunc) . ', php::ArgList{' . implode(', ', $argExprs) . '})';
    }

    // Same receiverPos semantics as genUniversalPhpFn.
    protected function genUniversalCppFn(string $receiver, string $cppFunc, array $args, int $receiverPos = 0): string
    {
        $argExprs = $this->buildReceiverArgs($receiver, $args, $receiverPos);
        return $cppFunc . '(' . implode(', ', $argExprs) . ')';
    }

    protected function buildReceiverArgs(string $receiver, array $args, int $receiverPos): array
    {
        $userArgs = [];
        foreach ($args as $arg) {
            $userArgs[] = $this->parseExpr($arg->value);
        }

        if ($receiverPos === 0) {
            $argExprs = [$receiver];
            foreach ($userArgs as $ua) {
                $argExprs[] = $ua;
            }
        } else {
            $argExprs = [];
            foreach ($userArgs as $i => $ua) {
                if ($i === $receiverPos - 1) {
                    $argExprs[] = $receiver;
                }
                $argExprs[] = $ua;
            }
            if ($receiverPos > count($userArgs)) {
                $argExprs[] = $receiver;
            }
        }

        return $argExprs;
    }

    protected function genUniversalPhpFnRef(string $receiver, string $phpFunc, array $args, string $returnType): string
    {
        $tmpRef = $this->addTmpVar(self::TYPE_REF);
        $tmpVar = $this->addTmpVar(self::TYPE_VAR);
        $argExprs = ['&' . $tmpRef];
        foreach ($args as $arg) {
            $argExprs[] = $this->parseExpr($arg->value);
        }
        $this->context->beforeStmtLines[] = $tmpRef . ' = ' . $receiver . '.toReference();';
        $this->context->beforeStmtLines[] = $tmpVar . ' = php::call(' . $this->getFuncPtr($phpFunc) . ', php::ArgList{' . implode(', ', $argExprs) . '});';
        return $tmpVar;
    }
}
