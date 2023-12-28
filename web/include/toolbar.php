<?php
$config = include ROOT_PATH . '/include/config.php';
?>

<p style="height: 36px">
    风格:
    <?php include ROOT_PATH . '/include/style.php'; ?>
    字体:
    <?php include ROOT_PATH . '/include/font.php'; ?>
    字体尺寸:
    <input id="inputFontSize" type="number" step="1" value="<?= $config['font-size'] ?>" style="width: 40px;"/>
    行高 (%):
    <input id="lineHeight" type="number" step="10" value="<?= $config['line-height'] ?>" style="width: 50px;"/>
</p>
