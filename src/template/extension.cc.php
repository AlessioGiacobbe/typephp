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

// class register functions
<?php
foreach ($this->classCeList as $ce):
?>
zend_class_entry * <?= $ce ?>;
<?php endforeach; ?>

// class entry
zend_class_entry *<?= Translator::PREFIX . Translator::CLASS_ENTRY_MAP . '[' . count($this->classMap) . ']' ?>;

zend_class_entry *php_get_class_entry(int class_id, const char *class_name) {
    if (<?= Translator::PREFIX . Translator::CLASS_ENTRY_MAP ?>[class_id] == nullptr) {
        <?= Translator::PREFIX . Translator::CLASS_ENTRY_MAP ?>[class_id] = php::getClassEntrySafe(class_name);
    }
    return <?= Translator::PREFIX . Translator::CLASS_ENTRY_MAP ?>[class_id];
}

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

// property offset
<?php
foreach ($this->classes as $classDef):
    foreach ($classDef->properties as $propertyDef):
?>
uint32_t <?=Translator::PREFIX . $this->getPropertyOffset($propertyDef->name, $classDef->name, $classDef->namespace)?>;
<?php
    endforeach;
endforeach;
?>

// clang-format off
static const zend_function_entry ext_functions[] = {
<?php
foreach ($this->functions as $functionDef):
?>
    ZEND_FE(<?=$functionDef->name?>, arginfo_<?=$functionDef->name?>)
<?php endforeach;?>
    ZEND_FE_END
};
// clang-format on

static PHP_MINIT_FUNCTION(<?=$this->targetName?>) {
// class/interface class entries
<?php
foreach ($this->classCeList as $ce):
    $info = $this->classCeInfo[$ce] ?? $this->getInternalCeInfo($ce);
?>
    <?=$ce?> = <?= $info['func'] ?>(<?= $info['args'] ?>);
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

    // property offset
<?php
foreach ($this->classes as $classDef):
    foreach ($classDef->properties as $propertyDef):
        ?>
    <?=Translator::PREFIX . $this->getPropertyOffset($propertyDef->name, $classDef->name, $classDef->namespace)?> = php::getPropertyOffset("<?=$classDef->getNamespacedName(false)?>", "<?=$propertyDef->name?>");
    <?php
    endforeach;
endforeach;
?>

// class entry
<?php
foreach ($this->classMap as $class => $id):
    ?>
    <?= Translator::PREFIX . Translator::CLASS_ENTRY_MAP . '[' . $id . ']' ?> = php::getClassEntry("<?= $this->escapeString($class) ?>");
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

zend_module_entry <?=$this->targetName?>_module_entry = {
    STANDARD_MODULE_HEADER,
    "<?=$this->targetName?>",
    ext_functions,
    PHP_MINIT(<?=$this->targetName?>),
    nullptr,
    nullptr,
    nullptr,
    nullptr,
    nullptr,
    STANDARD_MODULE_PROPERTIES,
};

#ifdef BUILD_PHP_EXTENSION
ZEND_GET_MODULE(<?=$this->targetName?>);
#else
zend_module_entry *<?= self::PREFIX . 'embed_' ?>get_module() {
    return &<?= $this->targetName ?>_module_entry;
}
#endif
