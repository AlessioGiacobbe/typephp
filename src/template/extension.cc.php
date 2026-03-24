<?php
/**
 * @var $this Translator
 */
use PhpAot\Php\Translator;
echo $this->genIncludeHeaderFiles();
?>

#include "ps_title.h"
#include "php_cli_process_title.h"
#include "php_cli_process_title_arginfo.h"

// global vars
<?php
// 全局变量只能是 var 类型
foreach ($this->globalVars as $name => $type):
?>
<?= Translator::TYPE_VAR ?>  <?= $name ?>;
<?php endforeach; ?>

// class register functions
<?php
foreach ($this->classCeList as $ce):
?>
zend_class_entry * <?= $ce ?>;
<?php endforeach; ?>

// class entry
zend_class_entry *<?= Translator::PREFIX . Translator::CLASS_MAP . '[' . count($this->classMap) . ']' ?>;

// func
zend_function *<?= Translator::PREFIX . Translator::FUNC_MAP . '[' . count($this->funcMap) . ']' ?>;

// property
uint32_t <?= Translator::PREFIX . Translator::PROP_MAP . '[' . count($this->propMap) . ']' ?>;

zend_class_entry *php_get_class(int class_id, const php::String &class_name) {
    if (UNEXPECTED(<?= Translator::PREFIX . Translator::CLASS_MAP ?>[class_id] == nullptr)) {
        <?= Translator::PREFIX . Translator::CLASS_MAP ?>[class_id] = php::getClassEntrySafe(class_name);
    }
    return <?= Translator::PREFIX . Translator::CLASS_MAP ?>[class_id];
}

zend_function *php_get_func(int func_id, const php::String &func_name) {
    if (UNEXPECTED(<?= Translator::PREFIX . Translator::FUNC_MAP ?>[func_id] == nullptr)) {
        <?= Translator::PREFIX . Translator::FUNC_MAP ?>[func_id] = php::getFunction(func_name);
    }
    return <?= Translator::PREFIX . Translator::FUNC_MAP ?>[func_id];
}

zend_function *php_get_method(int func_id, const php::Str &method_name, int class_id, const php::Str &class_name) {
    if (UNEXPECTED(<?= Translator::PREFIX . Translator::FUNC_MAP ?>[func_id] == nullptr)) {
        auto ce = php_get_class(class_id, class_name);
        <?= Translator::PREFIX . Translator::FUNC_MAP ?>[func_id] = php::getMethod(ce, method_name);
    }
    return <?= Translator::PREFIX . Translator::FUNC_MAP ?>[func_id];
}

uint32_t php_get_prop(int prop_id, const php::Str &prop_name, int class_id, const php::Str &class_name) {
    if (UNEXPECTED(<?= Translator::PREFIX . Translator::PROP_MAP ?>[prop_id] == 0)) {
        <?= Translator::PREFIX . Translator::PROP_MAP ?>[prop_id] = php::getPropertyOffset(class_name, prop_name);
    }
    return <?= Translator::PREFIX . Translator::PROP_MAP ?>[prop_id];
}

// literal strings
php::Str <?=Translator::LITERAL_STRINGS?>[] = {
<?php
foreach ($this->literalStrings as $str => $index):
?>
    php::String{ZEND_STRL("<?=$this->escapeString($str)?>"), true}, // [<?=$index?>]
<?php endforeach; ?>
};

// constants
<?php
foreach ($this->nativeConstants as $name => $const):
?>
<?=$const->type?> <?=$name?>;
<?php endforeach; ?>

    // class
<?php
foreach ($this->classes as $classDef) :
    if ($classDef->requireCtor) {
        echo "static zend_object* (*create_object_" . $classDef->getNamespacedName() . ")(zend_class_entry *class_type);";
    }
    foreach ($classDef->constants as $constant) :
        if ($constant->type === Translator::TYPE_ARRAY) :
            $constName = Translator::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
            echo Translator::TYPE_VAR . " " . $constName . ";\n";
        endif;
    endforeach;
endforeach;
?>

// clang-format off
static const zend_function_entry ext_functions[] = {
    PHP_FE(cli_set_process_title,        arginfo_cli_set_process_title)
    PHP_FE(cli_get_process_title,        arginfo_cli_get_process_title)
<?php
foreach ($this->functions as $functionDef):
    if ($this->buildMode === 'ext' and $functionDef->name === 'main') {
        continue;
    }
    $zif_name = $this->escapeZendFnName($functionDef->getNamespacedName());
?>
<?php if ($functionDef->namespace): ?>
    ZEND_NAMED_FE("<?=$this->escapeString($functionDef->getNamespacedName())?>", ZEND_FN(<?= $zif_name ?>), arginfo_<?=$zif_name?>)
<?php else: ?>
    ZEND_FE(<?=$zif_name?>, arginfo_<?=$zif_name?>)
<?php endif; ?>
<?php endforeach;?>
    ZEND_FE_END
};
// clang-format on

PHP_MINIT_FUNCTION(<?=$this->getModuleName()?>) {
// class/interface class entries
<?php
foreach ($this->classCeList as $ce):
    $info = $this->classCeInfo[$ce] ?? $this->getInternalCeInfo($ce);
?>
    <?=$ce?> = <?= $info['func'] ?>(<?= $info['args'] ?>);
<?php
    if (!empty($info['classDef']) and $info['classDef']->requireCtor):
        $className = $info['classDef']->getNamespacedName();
?>
    create_object_<?=$className?> = php_get_create_object_fn(<?=$ce?>);
    <?=$ce?>->create_object = [](zend_class_entry *class_type) -> zend_object* {
        auto obj = create_object_<?= $className ?>(class_type);
        <?php foreach ($info['classDef']->properties as $property):
            $fullPropName = $info['classDef']->getNamespacedName(true) . '::' . $property->name;
        ?>
        <?php if (isset($this->defaultPropertyList[$fullPropName])): ?>
        auto value = <?=$this->defaultPropertyList[$fullPropName]?>;
        zend_update_property_ex(obj->ce, obj, <?=$this->getLiteralString($property->name)?>.str(), value.ptr());
        <?php endif; ?>
        <?php endforeach; ?>
        return obj;
    };
<?php endif; ?>
<?php endforeach; ?>

// register symbols
<?php
foreach ($this->registerSymbols as $registerSymbolFn):
?>
    <?= $registerSymbolFn ?>(module_number);
<?php endforeach; ?>

    return SUCCESS;
}

void php_app_init() {
    // register constants
<?php
foreach ($this->nativeConstants as $name => $const):
?>
    <?=$name?> = <?= $const->value ?>;
    php::define("<?=$this->escapeString($const->name)?>", <?= $name ?>);
<?php endforeach; ?>

    // global vars
<?php
foreach ($this->globalVars as $name => $type):
?>
    php::initGlobal("<?=$name?>", <?= $name ?>);
<?php endforeach; ?>

    // static property
<?php
foreach ($this->defaultStaticPropertyList as $prop):
?>
    php::setStaticProperty(<?=$this->genCharPtr($prop->class, true)?>, <?=$this->genCharPtr($prop->name)?>, <?=$prop->default?>);
<?php
endforeach;
?>

    // class array constants
    <?=$this->genClassArrayConstants();?>
}

void php_app_clean() {
<?php
foreach ($this->globalVars as $name => $type) :
?>
    <?= $name ?>.unset();
    php::unsetGlobal("<?=$name?>");
<?php endforeach; ?>
<?php
foreach ($this->nativeConstants as $name => $const):
    if ($const->type !== Translator::TYPE_VAR) {
        continue;
    }
?>
    <?= $name ?>.unset();
<?php endforeach; ?>

    // class array constants
<?php
foreach ($this->classes as $classDef) :
    foreach ($classDef->constants as $constant) :
        if ($constant->type === Translator::TYPE_ARRAY) :
            $constName = Translator::PREFIX . $this->getNativeName($constant->name, $classDef->namespace, $classDef->name);
            echo $constName . ".unset();\n";
        endif;
    endforeach;
endforeach;
?>
}

PHP_RINIT_FUNCTION(<?=$this->getModuleName()?>) {
    php::request_init();
    php_app_init();

<?php if ($this->buildMode === 'bin'): ?>
<?php if (count($this->functions['main']->argInfoList) == 2): ?>
    php::eval("global $argc, $argv; main($argc, $argv);");
<?php else:?>
    php::eval("main();");
<?php endif; ?>
<?php endif; ?>

    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(<?=$this->getModuleName()?>) {
    php_app_clean();
    php::request_shutdown();
    return SUCCESS;
}

zend_module_entry <?=$this->getModuleName()?>_module_entry = {
    STANDARD_MODULE_HEADER,
    "<?=$this->getModuleName()?>",
    ext_functions,
    PHP_MINIT(<?=$this->getModuleName()?>),
    nullptr,
    PHP_RINIT(<?=$this->getModuleName()?>),
    PHP_RSHUTDOWN(<?=$this->getModuleName()?>),
    nullptr,
    nullptr,
    STANDARD_MODULE_PROPERTIES,
};

<?php if ($this->buildMode === 'ext'): ?>
ZEND_GET_MODULE(<?=$this->getModuleName()?>);
<?php else: ?>
zend_module_entry *<?= Translator::PREFIX . 'embed_' ?>get_module() {
    return &<?= $this->getModuleName() ?>_module_entry;
}
<?php endif; ?>