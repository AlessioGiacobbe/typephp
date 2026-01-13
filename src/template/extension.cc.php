<?php
/**
 * @var $this Translator
 */
use PhpAot\Php\Translator;
echo $this->genIncludeHeaderFiles();
?>

// global vars
<?php
// 全局变量只能是 var 类型
foreach ($this->globalVars as $name => $type):
?>
<?= Translator::TYPE_VAR ?>  <?= $name ?>;
<?php endforeach; ?>

// class entries
<?php
foreach ($this->classes as $classDef) :
?>
zend_class_entry * <?= $this->getClassCe($classDef) ?>;
<?php endforeach; ?>

// class register functions
<?php
foreach ($this->classes as $classDef):
    $name = $classDef->getNamespacedName();
    $parentCe = $classDef->extends ? 'zend_class_entry *parent_ce' : '';
?>
extern zend_class_entry *<?= $this->getRegisterClassFunction($name) ?>(<?= $parentCe ?>);
<?php endforeach; ?>

// literal strings
php::Var <?=Translator::LITERAL_STRINGS?>[] = {
<?php
foreach ($this->literalStrings as $str => $index):
?>
    php::String{ZEND_STRL("<?=$this->escapeString($str)?>"), true},
<?php endforeach; ?>
};

// constants
<?php
foreach ($this->nativeConstants as $name => $constant):
?>
<?=$constant->type?> <?=$name?>;
<?php endforeach; ?>

static ZEND_FUNCTION(main) {
    php_main();
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_void, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry ext_functions[] = {
    ZEND_FE(main, arginfo_void)
    ZEND_FE_END
};

static PHP_MINIT_FUNCTION(app) {
<?php
foreach ($this->classes as $classDef):
    $name = $classDef->getNamespacedName();
    $parentCe = $this->getParentClassCe($classDef);
    $fn = $this->getRegisterClassFunction($name);
?>
    <?=$this->getClassCe($classDef)?> = <?= $fn ?>(<?= $parentCe ?>);
<?php endforeach; ?>

    return SUCCESS;
}

void php_app_init() {
    // register constants
<?php
foreach ($this->nativeConstants as $name => $constant):
?>
    <?=$name?> = <?= $constant->value ?>;
    php::define("<?=$name?>", <?= $name ?>);
<?php endforeach; ?>

    // global vars
<?php
foreach ($this->globalVars as $name => $type):
?>
    <?= $name ?> = php::global("<?=$name?>");
<?php endforeach; ?>
}

void php_app_clean() {
<?php
foreach ($this->globalVars as $name => $type) :
?>
    <?= $name ?>.unset();
<?php endforeach; ?>
<?php
foreach ($this->nativeConstants as $name => $constant):
    if ($constant->type !== Translator::TYPE_VAR) {
        continue;
    }
?>
    <?= $name ?>.unset();
<?php endforeach; ?>
}

static zend_module_entry app_module_entry = {
    STANDARD_MODULE_HEADER,
    "app",
    ext_functions,
    PHP_MINIT(app),
    nullptr,
    nullptr,
    nullptr,
    nullptr,
    nullptr,
    STANDARD_MODULE_PROPERTIES,
};

ZEND_GET_MODULE(app);
