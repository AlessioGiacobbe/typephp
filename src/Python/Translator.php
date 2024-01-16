<?php

namespace PhpAot\Python;

class Translator
{
    private array $keywords = ['abs', 'aiter', 'all', 'anext', 'any', 'ascii', 'bin', 'bool', 'breakpoint', 'bytearray', 'bytes', 'callable', 'chr', 'classmethod', 'compile', 'complex', 'copyright', 'credits', 'delattr', 'dict', 'dir', 'divmod', 'enumerate', 'eval', 'exec', 'exit', 'filter', 'float', 'format', 'frozenset', 'getattr', 'globals', 'hasattr', 'hash', 'help', 'hex', 'id', 'input', 'int', 'isinstance', 'issubclass', 'iter', 'len', 'license', 'list', 'locals', 'map', 'max', 'memoryview', 'min', 'next', 'object', 'oct', 'open', 'ord', 'pow', 'print', 'property', 'quit', 'range', 'repr', 'reversed', 'round', 'set', 'setattr', 'slice', 'sorted', 'staticmethod', 'str', 'sum', 'super', 'tuple', 'type', 'vars', 'zip'];
    private array $keywordsMap = [];
    private int $indentLevel = 0;
    private string $indentStr = "\t";
    public string $mode;
    private array $definedFunctions = [];
    private array $builtinTypes = [
        'ArithmeticError',
        'AssertionError',
        'AttributeError',
        'BaseException',
        'BaseExceptionGroup',
        'BlockingIOError',
        'BrokenPipeError',
        'BufferError',
        'BytesWarning',
        'ChildProcessError',
        'ConnectionAbortedError',
        'ConnectionError',
        'ConnectionRefusedError',
        'ConnectionResetError',
        'DeprecationWarning',
        'EOFError',
        'Ellipsis',
        'EncodingWarning',
        'EnvironmentError',
        'Exception', 'ExceptionGroup', 'False',
        'FileExistsError', 'FileNotFoundError', 'FloatingPointError',
        'FutureWarning', 'GeneratorExit', 'IOError', 'ImportError', 'ImportWarning',
        'IndentationError', 'IndexError', 'InterruptedError', 'IsADirectoryError',
        'KeyError', 'KeyboardInterrupt', 'LookupError', 'MemoryError', 'ModuleNotFoundError',
        'NameError', 'None', 'NotADirectoryError', 'NotImplemented', 'NotImplementedError',
        'OSError', 'OverflowError', 'PendingDeprecationWarning', 'PermissionError', 'ProcessLookupError',
        'RecursionError', 'ReferenceError', 'ResourceWarning', 'RuntimeError', 'RuntimeWarning',
        'StopAsyncIteration', 'StopIteration', 'SyntaxError', 'SyntaxWarning',
        'SystemError', 'SystemExit', 'TabError', 'TimeoutError', 'True', 'TypeError',
        'UnboundLocalError', 'UnicodeDecodeError', 'UnicodeEncodeError',
        'UnicodeError', 'UnicodeTranslateError', 'UnicodeWarning', 'UserWarning', 'ValueError', 'Warning', 'ZeroDivisionError'
    ];

    function __construct()
    {
        $this->keywordsMap = array_flip($this->keywords);
        $this->builtinTypes = array_flip($this->builtinTypes);
    }

    function setMode($mode)
    {
        $this->mode = $mode;
    }

    function setIndent(string $indent)
    {
        $this->indentStr = $indent;
    }

    function prepare($root)
    {
        foreach ($root->body as $body) {
            if ($body->_type == 'FunctionDef') {
                $this->definedFunctions[$body->name] = 1;
            }
        }
    }

    function parseAttribute($attr)
    {
        switch ($attr->_type) {
            case 'Name':
                return '$' . $attr->id;
            case 'Attribute':
                return $this->parseAttribute($attr->value) . '->' . $attr->attr;
            case 'Constant':
                return 'PyCore::str("' . $attr->s . '")';
            case 'Call':
                return $this->parseCall($attr);
            case 'Subscript':
                return $this->parseSubscript($attr);
            case 'BinOp':
                return $this->parseBinOp($attr);
            default:
                var_dump(__METHOD__, __LINE__);
                debug($attr);
        }
    }

    function parseFunc($fn)
    {
        switch ($fn->_type) {
            case 'Name':
                $id = $fn->id;
                if (isset($this->keywordsMap[$id])) {
                    return 'PyCore::' . $id;
                } elseif (isset($this->definedFunctions[$id])) {
                    return $id;
                } elseif (isset($this->builtinTypes[$id])) {
                    return '$builtins->' . $id;
                } else {
                    return '$' . $id;
                }
            case 'Attribute':
                return $this->parseAttribute($fn->value) . '->' . $fn->attr;
            default:
                debug($fn);
        }
    }

    function parseCall($call): string
    {
        $fn = $this->parseFunc($call->func);
        if_empty_debug($fn, $call);
        $args = $call->args;
        $kwargs = $call->keywords;

        $args_list = [];

        foreach ($args as $arg) {
            $args_list[] = $this->parseValue($arg);
        }

        foreach ($kwargs as $arg) {
            $name = $arg->arg;
            $args_list[] = $name . ': ' . $this->parseValue($arg->value);
        }

        if (empty($args_list)) {
            return "$fn()";
        } else {
            return "$fn(" . implode(', ', $args_list) . ")";
        }
    }

    static function valueToRepr($v, $python = false)
    {
        if (is_string($v)) {
            $v = str_replace(
                ["\\", "\n", "\r", "\t", "\v", "\x00", "\""],
                ["\\\\", "\\n", "\\r", "\\t", "\\v", "\\x00", "\\\""],
                $v);
            return "\"$v\"";
        } elseif ($v === []) {
            return '[]';
        } elseif (is_numeric($v)) {
            if ($python) {
                if (is_infinite($v)) {
                    return "float('inf')";
                } elseif (is_nan($v)) {
                    return "float('nan')";
                }
            }
            return strval($v);
        } elseif (is_bool($v)) {
            return $python ? ($v ? 'True' : 'False') : ($v ? 'true' : 'false');
        } elseif (is_null($v)) {
            return $python ? 'None' : 'null';
        } elseif (is_array($v)) {
            return var_export($v, true);
        } else {
            return $python ? 'None' : 'null';
        }
    }

    function parseConstant($value): string
    {
        return self::valueToRepr($value->value);
    }

    function parseTuple($tuple)
    {
        $ids = [];
        foreach ($tuple->dims as $dim) {
            $ids[] = $this->parseTarget($dim);
        }
        return '[' . implode(', ', $ids) . ']';
    }

    function parseListComp($listComp)
    {
        $code = '';
        $this->indentLevel++;
        $code .= $this->getIndent() . '$___ = [];' . PHP_EOL;
        $recipient = '$___';
        $generators = $this->parseGenerators($listComp->generators, $recipient, $captures);
        $code .= $this->getIndent() . $generators;
        $code .= $this->getIndent() . 'return $___;' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '})(' . implode(',', $captures) . ')';
        return '(function(' . implode(',', $captures) . ') {' . PHP_EOL . $code;
    }

    function parseValue($value)
    {
        switch ($value->_type) {
            case 'Call':
                return $this->parseCall($value);
            case 'Constant':
                return $this->parseConstant($value);
            case 'Attribute':
                return $this->parseAttribute($value);
            case 'Name':
                return $this->parseName($value);
            case 'List':
                return $this->parseList($value);
            case 'Dict':
                return $this->parseDict($value);
            case 'Set':
                return $this->parseSet($value);
            case 'Tuple':
                return $this->parseTuple($value);
            case 'ListComp':
                return $this->parseListComp($value);
            case 'DictComp':
                return $this->parseDictComp($value);
            case 'BinOp':
                return $this->parseBinOp($value);
            case 'JoinedStr':
                return $this->parseJoinedStr($value);
            case 'Subscript':
                return $this->parseSubscript($value);
            case 'FormattedValue':
                return $this->parseFormattedValue($value);
            case 'UnaryOp':
                return $this->parseTarget($value);
            case 'BoolOp':
                return $this->parseBoolOp($value);
            case 'Compare':
                return $this->parseTest($value);
            case 'IfExp':
                return $this->parseIfExp($value);
            case 'Yield':
                return $this->parseYield($value);
            case 'Lambda':
                return $this->parseLambda($value);
            case 'GeneratorExp':
                return $this->parseGeneratorExp($value);
            default:
                debug($value);
                break;
        }
    }

    function parseSlice($slice, $op = 'Load')
    {
        if ($slice->_type == 'Slice') {
            $_args[] = $slice->lower ? $this->parseTarget($slice->lower) : 'null';
            $_args[] = $slice->upper ? $this->parseTarget($slice->upper) : 'null';
            $_args[] = $slice->step ? $this->parseTarget($slice->step) : 'null';
            $target = 'PyCore::slice(' . implode(', ', $_args) . ')';
        } else {
            $target = $this->parseTarget($slice);
        }
        if ($op == 'Store') {
            return $slice->id . '->__setitem__(' . $target . ', $__value)';
        } elseif ($op == 'Del') {
            return $slice->id . '->__delitem__(' . $target . ')';
        } else {
            return $slice->id . '->__getitem__(' . $target . ')';
        }
    }

    function parseImportFrom($node)
    {
        $module = $node->module;
        $imports = [];
        foreach ($node->names as $name) {
            $type = $name->name;
            $as = empty($name->asname) ? $type : $name->asname;
            $imports[] = "\$$as = PyCore::import('$module')->$type";
        }

        return implode(';' . PHP_EOL, $imports);
    }

    function parseImport($node)
    {
        $name = $node->names[0];
        $module = $name->name;
        $as = empty($name->asname) ? $module : $name->asname;
        return "\$$as = PyCore::import('$module')";
    }

    function parseTarget($target)
    {
        switch ($target->_type) {
            case 'Name':
                return '$' . $target->id;
            case 'UnaryOp':
                $operand = $this->parseTarget($target->operand);
                switch ($target->op->_type) {
                    case 'USub':
                        return '-' . $operand;
                    case 'Not':
                        return '!' . $operand;
                    default:
                        debug($target);
                        break;
                }
                break;
            case 'Tuple':
                return $this->parseTuple($target);
            case 'Subscript':
                return $this->parseSubscript($target);
            case 'IfExp':
                return $this->parseIfExp($target);
            case 'Attribute':
                return $this->parseAttribute($target);
            case 'Constant':
                return $this->parseConstant($target);
            case 'List':
                return $this->parseList($target);
            case 'Set':
                return $this->parseSet($target);
            case 'Call':
                return $this->parseCall($target);
            case 'BinOp':
                return $this->parseBinOp($target);
            case 'Compare':
                return $this->parseTest($target);
            case 'Slice':
                return $this->parseSlice($target);
            case 'BoolOp':
                return $this->parseBoolOp($target);
            case 'ListComp':
                return $this->parseListComp($target);
            default:
                debug($target);
                break;
        }
    }

    function parseIfExp($exp)
    {
        $test = $this->parseTest($exp->test);
        $orelse = $this->parseValue($exp->orelse);
        $body = $this->parseValue($exp->body);
        return $test . ' ? ' . $body . ' : ' . $orelse;
    }

    function parseExpr($node)
    {
        switch ($node->_type) {
            case 'Expr':
                if (is_string($node->value->value)) {
                    return '/** ' . $node->value->value . ' */' . PHP_EOL;
                } else {
                    return $this->parseValue($node->value) . ';';
                }
            default:
                return $this->parseValue($node->value) . ';';
        }
    }

    function parseArguments($args): string
    {
        $names = [];
        foreach ($args->args as $arg) {
            $names[] = '$' . $arg->arg;
        }
        if ($args->vararg) {
            $names[] = '...$' . $args->vararg->arg;
        }
        return implode(', ', $names);
    }

    function parseReturn($node)
    {
        return 'return ' . $this->parseValue($node->value);
    }

    function parseTest($test)
    {
        $comparator = null;
        $left = $test->left ? $this->parseValue($test->left) : '';
        if ($test->ops) {
            $ops = $test->ops;
            if (count($ops) != 1) {
                debug($test);
            }
            $op = $ops[0]->_type;
            $comparators = $test->comparators;
            $comparator = $comparators[0];
        } elseif ($test->op) {
            $op = $test->op->_type;
        } else {
            $op = '';
        }

        switch ($op) {
            case 'Is':
            case 'Eq':
                return $left . ' == ' . $this->parseValue($comparator);
            case 'NotEq':
                return $left . ' != ' . $this->parseValue($comparator);
            case 'In':
                return $this->parseValue($comparator) . '->__contains__(' . $left . ')';
            case 'NotIn':
                return '!' . $this->parseValue($comparator) . '->__contains__(' . $left . ')';
            case 'Gt':
                return $left . ' > ' . $this->parseValue($comparator);
            case 'Lt':
                return $left . ' < ' . $this->parseValue($comparator);
            case 'GtE':
                return $left . ' >= ' . $this->parseValue($comparator);
            case 'LtE':
                return $left . ' <= ' . $this->parseValue($comparator);
            case 'Not':
                return '!' . $this->parseValue($test->operand);
            case 'Or':
                return $this->parseOr($test->values);
            case 'And':
                return $this->parseAnd($test->values);
            default:
                return $this->parseTarget($test);
        }
    }

    function parseIf($node)
    {
        $expr = $this->parseTest($node->test);
        $this->indentLevel++;
        $body = $this->parseBody($node->body);
        $orelse = empty($node->orelse) ? '' : $this->parseBody($node->orelse);
        $this->indentLevel--;
        return 'if (' . $expr . ') {' .
            PHP_EOL . $body .
            PHP_EOL . $this->getIndent() . '}' .
            ($orelse ? ' else {' . PHP_EOL . $orelse . PHP_EOL . $this->getIndent() . '}' : '') .
            PHP_EOL;
    }

    function parseIter($iter)
    {
        switch ($iter->_type) {
            case 'Call':
                return $this->parseCall($iter);
            case 'List':
                return $this->parseList($iter);
            case 'Set':
                return $this->parseSet($iter);
            case 'Dict':
                return $this->parseDict($iter);
            case 'Name':
                return $this->parseName($iter);
            case 'Subscript':
                return $this->parseSubscript($iter);
            default:
                debug($iter);
        }
    }

    function parseSubscript($node)
    {
        $code = '';
        if ($node->value) {
            $code .= $this->parseValue($node->value);
        }
        if ($node->slice) {
            $code .= $this->parseSlice($node->slice, $node->ctx->_type);
        }
        return $code;
    }

    function parseFor($node)
    {
        $target = $this->parseTarget($node->target);
        $iter = $this->parseIter($node->iter);

        $code = '$__iter = PyCore::iter(' . $iter . ');' . PHP_EOL;
        $code .= $this->getIndent() . 'while($current = PyCore::next($__iter)) {' . PHP_EOL;

        $this->indentLevel++;
        $code .= $this->getIndent() . $target . ' = $current;' . PHP_EOL;
        $code .= $this->parseBody($node->body);
        $this->indentLevel--;

        return $code . $this->getIndent() . PHP_EOL . $this->getIndent() . '}';
    }

    private function getIndent()
    {
        return str_repeat($this->indentStr, $this->indentLevel);
    }

    function parseFunctionDef($node)
    {
        $name = $node->name;
        $args = $this->parseArguments($node->args);
        $fn = PHP_EOL . $this->getIndent() . 'function ' . $name . '(' . $args . ') {' . PHP_EOL;
        $this->indentLevel++;
        $fn .= $this->parseBody($node->body) . PHP_EOL;;
        $this->indentLevel--;
        $fn .= $this->getIndent() . '}' . PHP_EOL . PHP_EOL;

        return $fn;
    }

    private function addLine($line, array &$lines)
    {
        if ($this->mode == 'cli' && STEP) {
            echo $line . PHP_EOL;
        }
        $lines[] = $line;
    }

    private function parseLine($node, &$lines)
    {
        switch ($node->_type) {
            case 'ImportFrom':
                $line = $this->parseImportFrom($node) . ';';
                break;
            case 'Assign':
                $target = $this->parseTarget($node->targets[0]);
                $value = $this->parseValue($node->value);
                if ($node->targets[0]->_type == 'Subscript') {
                    $this->addLine('$__value = ' . $value . ';', $lines);
                    $line = "$target;";
                } else {
                    $line = "$target = $value;";
                }
                break;
            case 'AugAssign':
                $target = $this->parseTarget($node->target);
                $value = $this->parseValue($node->value);
                $line = "$target += $value;";
                break;
            case 'Import':
                $line = $this->parseImport($node) . ';';
                break;
            case 'Expr':
                $line = $this->parseExpr($node);
                break;
            case 'FunctionDef':
                $line = $this->parseFunctionDef($node);
                break;
            case 'Return':
                $line = $this->parseReturn($node) . ';';
                break;
            case 'If':
                $line = $this->parseIf($node);
                break;
            case 'For':
                $line = $this->parseFor($node);
                break;
            case 'While':
                $line = $this->parseWhile($node);
                break;
            case 'Try':
                $line = $this->parseTry($node);
                break;
            case 'Raise':
                $line = $this->parseRaise($node) . ';';
                break;
            case 'Subscript':
                $line = $this->parseSubscript($node) . ';';
                break;
            case 'With':
                $line = $this->parseWith($node);
                break;
            case 'Compare':
                $line = $this->parseTest($node);
                break;
            case 'Delete':
                $line = $this->parseDelete($node);
                break;
            case 'Continue':
                $line = $this->parseContinue($node) . ';';
                break;
            case 'Break':
                $line = $this->parseBreak($node) . ';';
                break;
            case 'Global':
                $line = $this->parseGlobal($node) . ';';
                break;
            case 'Pass':
                $line = $this->parsePass($node) . ';';
                break;
            case 'BinOp':
                $line = $this->parseBinOp($node) . ';';
                break;
            case 'IfExp':
                $line = $this->parseIfExp($node) . ';';
                break;
            case 'Tuple':
                $line = $this->parseTuple($node) . ';';
                break;
            case 'Lambda':
                $line = $this->parseLambda($node) . ';';
                break;
            case 'Call':
                $line = $this->parseCall($node) . ';';
                break;
            default:
                debug($node);
                $line = '';
                break;
        }
        $this->addLine($line, $lines);
    }

    function parseBody($tree)
    {
        $lines = [];
        foreach ($tree as $node) {
            $this->parseLine($node, $lines);
        }
        foreach ($lines as &$line) {
            $line = $this->getIndent() . $line;
        }
        return implode(PHP_EOL, $lines);
    }

    function convert($tree)
    {
        $this->prepare($tree);
        $output = '<?php' . PHP_EOL;
        $output .= '$operator = PyCore::import("operator");' . PHP_EOL;
        $output .= '$builtins = PyCore::import("builtins");' . PHP_EOL;
        $output .= $this->parseBody($tree->body);
        echo $output;
        echo PHP_EOL;
    }

    private function parseGenerators($generators, $recipient, &$captures)
    {
        $captures = [];
        $code = '';

        foreach ($generators as $k => $generator) {
            $target = $this->parseTarget($generator->target);
            if ($generator->iter->_type == 'Name') {
                $name = '$' . $generator->iter->id;
                $captures[] = $name;
            } else {
                $name = '$___iter';
                $code .= $this->getIndent() . '$___iter = ' . $this->parseIter($generator->iter) . ';' . PHP_EOL;
            }
            $code .= $this->getIndent() . 'foreach(' . $name . ' as $___i => ' . $target . ') {' . PHP_EOL;
            $this->indentLevel++;
            if (count($generators) === 1) {
                $code .= $this->getIndent() . $recipient . '[] = ' . $target . ';' . PHP_EOL;
            } else {
                $code .= $this->getIndent() . $recipient . '[$___i][' . $k . '] = ' . $target . ';' . PHP_EOL;
            }
            $this->indentLevel--;
            $code .= $this->getIndent() . '}' . PHP_EOL;
        }
        return $code;
    }

    private function parseBinOp($value)
    {
        $op = $value->op->_type;
        $left = $this->parseTarget($value->left);
        $right = $this->parseValue($value->right);
        switch ($op) {
            case 'Mod':
                return $left . ' % ' . $right;
            case 'Add':
                return $left . ' + ' . $right;
            case 'Sub':
                return $left . ' - ' . $right;
            case 'Mult':
                return $left . ' * ' . $right;
            case 'Div':
                return $left . ' / ' . $right;
            default:
                return '$operator->' . strtolower($op) . '(' . $left . ' , ' . $right . ')';
        }
    }

    private function parseJoinedStr($value)
    {
        $list = [];
        foreach ($value->values as $v) {
            $list[] = $this->parseValue($v);
        }
        return implode(' . ', $list);
    }

    private function parseList($target)
    {
        $values = [];
        foreach ($target->elts as $e) {
            $values[] = $this->parseValue($e);
        }
        return 'new PyList([' . implode(', ', $values) . '])';
    }

    private function parseYield($value)
    {
        return 'yield ' . $this->parseValue($value->value) . ';';
    }


    private function parseWith(mixed $node)
    {
        $items = $node->items;
        $body = $node->body;
        $code = '';
        $finally_code = '';

        $parseItem = function ($call, $target) use (&$code, &$finally_code) {
            $call = $this->parseCall($call);
            $target = empty($target) ? '$__' : $this->parseTarget($target);
            $code .= $target . '__object = ' . $call . ';' . PHP_EOL;;
            $code .= $target . ' = ' . $target . '__object->__enter__();' . PHP_EOL;
            $this->indentLevel++;
            $finally_code .= $this->getIndent() . $target . '__object->__exit__();' . PHP_EOL;
            $this->indentLevel--;
        };

        foreach ($items as $k => $item) {
            if ($item->context_expr->_type == 'Tuple') {
                $context_expr = $item->context_expr->dims;
                $optional_vars = $item->optional_vars->dims;
                foreach ($context_expr as $k2 => $dim) {
                    $parseItem($dim, $optional_vars[$k2]);
                }
            } else {
                $parseItem($item->context_expr, $item->optional_vars);
            }
        }

        $code .= 'try {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseBody($body);
        $this->indentLevel--;
        $code .= PHP_EOL . '} finally {' . PHP_EOL;
        $code .= $finally_code;
        $code .= '}' . PHP_EOL;
        return $code;
    }

    private function parseDict($dict)
    {
        $code = '[' . PHP_EOL;
        foreach ($dict->values as $k => $v) {
            if (empty($dict->keys[$k])) {
                debug($v);
            }
            $key = $dict->keys[$k];
            $this->indentLevel++;
            $code .= $this->getIndent() . $this->parseTarget($key) . ' => ' . $this->parseTarget($v) . ',' . PHP_EOL;
            $this->indentLevel--;
        }
        return 'new PyDict(' . $code . '])';
    }


    private function parseBoolOp($value)
    {
        switch ($value->op->_type) {
            case 'And':
                return $this->parseAnd($value->values);
            case 'Or':
                return $this->parseOr($value->values);
            default:
                debug($value);
                break;
        }
    }

    private function parseAnd($values)
    {
        $targets = [];
        foreach ($values as $v) {
            $targets[] = $this->parseTarget($v);
        }
        return implode(' and ', $targets);
    }

    private function parseOr($values)
    {
        $targets = [];
        foreach ($values as $v) {
            $targets[] = $this->parseTarget($v);
        }
        return implode(' or ', $targets);
    }

    private function parseDelete(mixed $node)
    {
        $code = '';
        foreach ($node->targets as $target) {
            $code .= $this->getIndent() . $this->parseSubscript($target) . ';' . PHP_EOL;
        }
        return $code;
    }

    private function parseSet($target)
    {
        $values = [];
        foreach ($target->elts as $e) {
            $values[] = $this->parseValue($e);
        }
        return 'new PySet([' . implode(', ', $values) . '])';
    }

    private function parseWhile(mixed $node)
    {
        $test = $this->parseTest($node->test);
        $code = 'while(' . $test . ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseBody($node->body);
        $this->indentLevel--;
        return $code . $this->getIndent() . PHP_EOL . $this->getIndent() . '}';
    }

    private function parseTry(mixed $node)
    {
        $code = 'try {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseBody($node->body);
        $this->indentLevel--;
        $code .= PHP_EOL . '}';

        if ($node->handlers) {
            $code .= ' catch(PyError $e) {' . PHP_EOL;
            $this->indentLevel++;
            foreach ($node->handlers as $k => $handler) {
                if ($handler->type->_type == 'Name') {
                    $id = $handler->type->id;
                    $type = isset($this->builtinTypes[$id]) ? '$builtins->' . $id : '$' . $id;
                } else {
                    $types = [];
                    foreach ($handler->type->dims as $dim) {
                        $id = $dim->id;
                        $types[] = isset($this->builtinTypes[$id]) ? '$builtins->' . $id : '$' . $id;
                    }
                    $type = 'new PyTuple([' . implode(', ', $types) . '])';
                }
                $code .= ($k == 0 ? $this->getIndent() . 'if' : ' elseif') . ' (PyCore::isinstance($e, ' . $type . ')) {' . PHP_EOL;
                $this->indentLevel++;
                $code .= $this->parseBody($node->body);
                $this->indentLevel--;
                $code .= PHP_EOL . $this->getIndent() . '}';
            }
            $code .= ' else {';
            $this->indentLevel++;
            $code .= PHP_EOL . $this->getIndent() . 'throw $e;';
            $this->indentLevel--;
            $code .= PHP_EOL . $this->getIndent() . '}' . PHP_EOL;
            $this->indentLevel--;
            $code .= $this->getIndent() . '}';
        }

        if ($node->finalbody) {
            $code .= ' finally {' . PHP_EOL;
            $this->indentLevel++;
            $code .= $this->parseBody($node->finalbody);
            $this->indentLevel--;
            $code .= PHP_EOL . $this->getIndent() . '}';
        }

        return $code;
    }

    private function parseRaise(mixed $node)
    {
        return 'throw ' . $this->parseCall($node->exc);
    }

    private function parseName($value)
    {
        return '$' . $value->id;
    }

    private function parseContinue(mixed $node)
    {
        return 'continue';
    }

    private function parseBreak(mixed $node)
    {
        return 'break';
    }

    private function parseGlobal(mixed $node)
    {
        $names = [];
        foreach ($node->names as $name) {
            $names[] = '$' . $name;
        }
        return 'global ' . implode(', ', $names);
    }

    private function parsePass(mixed $node)
    {
        return 'return';
    }

    private function parseLambda($value)
    {
        $lines = [];
        $args = $this->parseArguments($value->args);
        $code = 'function (' . $args . ') {' . PHP_EOL;
        $this->indentLevel++;
        $this->parseLine($value->body, $lines);
        $code .= $this->getIndent() . 'return ' . $lines[0] . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}';
        return $code;
    }

    private function parseFormattedValue($value)
    {
        $expr = $this->parseValue($value->value);
        if ($value->format_spec and $value->format_spec->_type == 'JoinedStr') {
            if (count($value->format_spec->values) != 1) {
                debug($value);
            }
            $format = $value->format_spec->values[0]->value;
            if ($format[0] == '.' and $format[-1] == 'f') {
                return 'round(' . $expr . ', ' . substr($format, 1, strlen($format) - 2) . ')';
            }
        } else {
            return $expr;
        }
    }

    private function parseDictComp($node)
    {
        $code = '';
        $this->indentLevel++;
        $code .= $this->getIndent() . '$___ = [];' . PHP_EOL;
        $recipient = '$___';
        $generators = $this->parseGenerators($node->generators, $recipient, $captures);
        $code .= $this->getIndent() . $generators;
        $code .= $this->getIndent() . 'return $___;' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '})(' . implode(',', $captures) . ')';
        return '(function(' . implode(',', $captures) . ') {' . PHP_EOL . $code;
    }

    private function parseGeneratorExp($value)
    {
        debug($value);
    }
}
