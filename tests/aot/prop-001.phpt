--TEST--
Property
--SKIPIF--
<?php die('skip: not supported');
--FILE--
<?php
class Worker {
    public $context;
}

/**
 * 原始代码（有缺陷）
 */
function originalLogic($worker, $prop) {
    // ⚠️ 有缺陷的写法：缺少 $worker->context 存在性检查
    !isset($worker->$prop) && !isset($worker->context->$prop) && $worker->context->$prop = 'NNNN';
}

/**
 * 改进后的安全写法
 */
function safeLogic($worker, $prop) {
    // ✅ 安全写法：先确保 context 存在，再使用空合并赋值
    if (!isset($worker->context)) {
        $worker->context = new stdClass();
    }
    $worker->context->$prop ??= 'NNNN';
}

/**
 * 测试工具函数
 */
function dumpState($label, $worker, $prop) {
    echo "\n[$label]\n";
    echo "  worker->$prop: " . (isset($worker->$prop) ? "'" . $worker->$prop . "'" : 'UNSET') . "\n";
    echo "  context->$prop: " . (
        isset($worker->context) && isset($worker->context->$prop)
            ? "'" . $worker->context->$prop . "'"
            : (isset($worker->context) ? 'UNSET' : 'CONTEXT_NULL')
    ) . "\n";
}

function main() {
    echo "=".str_repeat("=", 70) . "\n";
    echo "TEST SUITE: isset + assignment short-circuit logic\n";
    echo "=".str_repeat("=", 70) . "\n";

    $prop = 'name';

    // ========================================================================
    // 测试 1: 两个属性都不存在 → 应触发赋值
    // ========================================================================
    echo "\n【TEST 1】两个属性都不存在 → 应触发赋值 'NNNN'\n";
    $worker = new Worker();
    $worker->context = new stdClass();

    dumpState('BEFORE', $worker, $prop);
    originalLogic($worker, $prop);
    dumpState('AFTER (original)', $worker, $prop);

    // 验证
    assert(isset($worker->context->name), "TEST 1 FAILED: context->name should be set");
    assert($worker->context->name === 'NNNN', "TEST 1 FAILED: value should be 'NNNN'");
    echo "✅ TEST 1 PASSED\n";

    // ========================================================================
    // 测试 2: $worker->$prop 存在 → 不应触发赋值
    // ========================================================================
    echo "\n【TEST 2】\$worker->\$prop 存在 → 不应触发赋值\n";
    $worker = new Worker();
    $worker->context = new stdClass();
    $worker->name = 'Alice';  // worker 有值

    dumpState('BEFORE', $worker, $prop);
    originalLogic($worker, $prop);
    dumpState('AFTER (original)', $worker, $prop);

    // 验证：context->name 应该仍不存在
    assert(!isset($worker->context->name), "TEST 2 FAILED: context->name should NOT be set");
    echo "✅ TEST 2 PASSED\n";

    // ========================================================================
    // 测试 3: $worker->context->$prop 存在 → 不应触发赋值
    // ========================================================================
    echo "\n【TEST 3】\$worker->context->\$prop 存在 → 不应触发赋值\n";
    $worker = new Worker();
    $worker->context = new stdClass();
    $worker->context->name = 'Bob';  // context 有值

    dumpState('BEFORE', $worker, $prop);
    originalLogic($worker, $prop);
    dumpState('AFTER (original)', $worker, $prop);

    // 验证：context->name 应保持原值
    assert($worker->context->name === 'Bob', "TEST 3 FAILED: value should remain 'Bob'");
    echo "✅ TEST 3 PASSED\n";

    // ========================================================================
    // 测试 4: 两个属性都存在 → 不应触发赋值
    // ========================================================================
    echo "\n【TEST 4】两个属性都存在 → 不应触发赋值\n";
    $worker = new Worker();
    $worker->context = new stdClass();
    $worker->name = 'Charlie';
    $worker->context->name = 'David';

    dumpState('BEFORE', $worker, $prop);
    originalLogic($worker, $prop);
    dumpState('AFTER (original)', $worker, $prop);

    // 验证：context->name 应保持原值
    assert($worker->context->name === 'David', "TEST 4 FAILED: value should remain 'David'");
    echo "✅ TEST 4 PASSED\n";

    // ========================================================================
    // 测试 5: $worker->context 为 null → 原始代码会触发错误！
    // ========================================================================
    echo "\n【TEST 5】\$worker->context 为 null → 原始代码会触发 FATAL ERROR\n";
    $worker = new Worker();
    $worker->context = null;  // context 为 null

    dumpState('BEFORE', $worker, $prop);

    try {
        originalLogic($worker, $prop);
        echo "❌ TEST 5 FAILED: Should have thrown error!\n";
    } catch (Error $e) {
        echo "✅ TEST 5 PASSED: Caught expected error: " . $e->getMessage() . "\n";
    }

    // 安全写法测试
    $worker2 = new Worker();
    $worker2->context = null;
    safeLogic($worker2, $prop);
    dumpState('AFTER (safe)', $worker2, $prop);
    assert(isset($worker2->context->name), "TEST 5 SAFE FAILED: context should be created");
    echo "✅ TEST 5 SAFE PASSED: Safe logic handled null context\n";

    // ========================================================================
    // 测试 6: $worker->context 完全不存在（未定义）→ 原始代码会触发错误！
    // ========================================================================
    echo "\n【TEST 6】\$worker->context 未定义 → 原始代码会触发 NOTICE\n";
    $worker = new Worker();
    unset($worker->context);  // 完全移除 context 属性

    dumpState('BEFORE', $worker, $prop);

    // PHP 8.0+ 会抛出 Error，PHP 7.x 会触发 Notice
    try {
        originalLogic($worker, $prop);
        echo "❌ TEST 6 FAILED: Should have thrown error!\n";
    } catch (Error $e) {
        echo "✅ TEST 6 PASSED: Caught expected error: " . $e->getMessage() . "\n";
    } catch (Exception $e) {
        echo "⚠️ TEST 6: Notice triggered (PHP 7.x behavior)\n";
    }

    // 安全写法测试
    $worker2 = new Worker();
    unset($worker2->context);
    safeLogic($worker2, $prop);
    dumpState('AFTER (safe)', $worker2, $prop);
    assert(isset($worker2->context->name), "TEST 6 SAFE FAILED: context should be created");
    echo "✅ TEST 6 SAFE PASSED: Safe logic handled undefined context\n";

    // ========================================================================
    // 测试 7: 属性值为 null（isset 返回 false）
    // ========================================================================
    echo "\n【TEST 7】属性值为 null → isset 返回 false，应触发赋值\n";
    $worker = new Worker();
    $worker->context = new stdClass();
    $worker->name = null;          // null 值
    $worker->context->name = null; // null 值

    dumpState('BEFORE', $worker, $prop);
    originalLogic($worker, $prop);
    dumpState('AFTER (original)', $worker, $prop);

    // 验证：null 被视为"不存在"，应触发赋值
    assert($worker->context->name === 'NNNN', "TEST 7 FAILED: null should trigger assignment");
    echo "✅ TEST 7 PASSED\n";

    // ========================================================================
    // 测试 8: 属性值为假值（0, '', false）→ isset 返回 true，不应触发赋值
    // ========================================================================
    echo "\n【TEST 8】属性值为假值（0, '', false）→ isset 返回 true\n";

    // 子测试 8a: 空字符串
    $worker = new Worker();
    $worker->context = new stdClass();
    $worker->context->name = '';
    originalLogic($worker, $prop);
    assert($worker->context->name === '', "TEST 8a FAILED: empty string should NOT trigger assignment");
    echo "✅ TEST 8a PASSED: empty string preserved\n";

    // 子测试 8b: 数字 0
    $worker = new Worker();
    $worker->context = new stdClass();
    $worker->context->name = 0;
    originalLogic($worker, $prop);
    assert($worker->context->name === 0, "TEST 8b FAILED: zero should NOT trigger assignment");
    echo "✅ TEST 8b PASSED: zero preserved\n";

    // 子测试 8c: false
    $worker = new Worker();
    $worker->context = new stdClass();
    $worker->context->name = false;
    originalLogic($worker, $prop);
    assert($worker->context->name === false, "TEST 8c FAILED: false should NOT trigger assignment");
    echo "✅ TEST 8c PASSED: false preserved\n";

    // ========================================================================
    // 测试 9: 动态属性名（变量）
    // ========================================================================
    echo "\n【TEST 9】动态属性名（变量）\n";
    $worker = new Worker();
    $worker->context = new stdClass();
    $dynamicProp = 'email';

    originalLogic($worker, $dynamicProp);
    assert(isset($worker->context->email), "TEST 9 FAILED: dynamic property should be set");
    assert($worker->context->email === 'NNNN', "TEST 9 FAILED: value should be 'NNNN'");
    echo "✅ TEST 9 PASSED\n";

    // ========================================================================
    // 测试 10: 多次调用（幂等性）
    // ========================================================================
    echo "\n【TEST 10】多次调用（幂等性）\n";
    $worker = new Worker();
    $worker->context = new stdClass();

    originalLogic($worker, $prop);  // 第一次：赋值
    $firstValue = $worker->context->name;

    originalLogic($worker, $prop);  // 第二次：不应覆盖
    $secondValue = $worker->context->name;

    assert($firstValue === 'NNNN', "TEST 10 FAILED: first call should set value");
    assert($secondValue === 'NNNN', "TEST 10 FAILED: second call should not change value");
    assert($firstValue === $secondValue, "TEST 10 FAILED: value should be idempotent");
    echo "✅ TEST 10 PASSED: Idempotent behavior confirmed\n";

    // ========================================================================
    // 总结
    // ========================================================================
    echo "\n".str_repeat("=", 70);
    echo "\n✅ ALL TESTS COMPLETED SUCCESSFULLY\n";
    echo "=".str_repeat("=", 70) . "\n";
}
?>
--EXPECT--
string(10) "Processing"
string(7) "unknown"
string(2) "OK"
string(2) "OK"