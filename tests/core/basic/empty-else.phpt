--TEST--
empty else
--FILE--
<?php
function main() {
    if (true) {
        echo 'success';
    } else {
        // 1
        # 1
        /**
         * 1
         * 2
         */
    }
}
?>
--EXPECT--
success
