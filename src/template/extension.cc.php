<?php
/**
 * @var Translator $this
 */
use PhpAot\Php\Translator;

echo $this->genIncludeHeaderFiles();
?>

// global vars
<?php
// 全局变量只能是 var 类型
foreach ($this->globalVars as $name => $type) {
    ?>
<?php echo Translator::TYPE_VAR; ?>  <?php echo $name; ?>;
<?php } ?>

// class register functions
<?php
foreach ($this->classCeList as $ce) {
    ?>
zend_class_entry * <?php echo $ce; ?>;
<?php } ?>

// class entry
zend_class_entry *<?php echo Translator::PREFIX . Translator::CLASS_MAP . '[' . count($this->classMap) . ']'; ?>;

// func
zend_function *<?php echo Translator::PREFIX . Translator::FUNC_MAP . '[' . count($this->funcMap) . ']'; ?>;

zend_class_entry *php_get_class(int class_id, const php::String &class_name) {
    if (UNEXPECTED(<?php echo Translator::PREFIX . Translator::CLASS_MAP; ?>[class_id] == nullptr)) {
        <?php echo Translator::PREFIX . Translator::CLASS_MAP; ?>[class_id] = php::getClassEntrySafe(class_name);
    }
    return <?php echo Translator::PREFIX . Translator::CLASS_MAP; ?>[class_id];
}

zend_function *php_get_func(int func_id, const php::String &func_name) {
    if (UNEXPECTED(<?php echo Translator::PREFIX . Translator::FUNC_MAP; ?>[func_id] == nullptr)) {
        <?php echo Translator::PREFIX . Translator::FUNC_MAP; ?>[func_id] = php::getFunction(func_name);
    }
    return <?php echo Translator::PREFIX . Translator::FUNC_MAP; ?>[func_id];
}

zend_function *php_get_method(int func_id, const php::Str &method_name, int class_id, const php::Str &class_name) {
    if (UNEXPECTED(<?php echo Translator::PREFIX . Translator::FUNC_MAP; ?>[func_id] == nullptr)) {
        auto ce = php_get_class(class_id, class_name);
        <?php echo Translator::PREFIX . Translator::FUNC_MAP; ?>[func_id] = php::getMethod(ce, method_name);
    }
    return <?php echo Translator::PREFIX . Translator::FUNC_MAP; ?>[func_id];
}

// literal strings
php::Str <?php echo Translator::LITERAL_STRINGS; ?>[] = {
<?php
foreach ($this->literalStrings as $str => $index) {
    ?>
    php::String{ZEND_STRL("<?php echo $this->escapeString($str); ?>"), true},
<?php } ?>
};

// constants
<?php
foreach ($this->nativeConstants as $name => $const) {
    ?>
<?php echo $const->type; ?> <?php echo $name; ?>;
<?php } ?>

// property offset
<?php
foreach ($this->classes as $classDef) {
    foreach ($classDef->properties as $propertyDef) {
        ?>
uint32_t <?php echo Translator::PREFIX . $this->getPropertyOffset($propertyDef->name, $classDef->name, $classDef->namespace); ?>;
<?php
    }
}
?>

// clang-format off
static const zend_function_entry ext_functions[] = {
<?php
foreach ($this->functions as $functionDef) {
    if ($this->buildMode === 'ext' and $functionDef->name === 'main') {
        continue;
    }
    $zif_name = $this->escapeZendFnName($functionDef->getNamespacedName());
    ?>
<?php if ($functionDef->namespace) { ?>
    ZEND_NAMED_FE("<?php echo $this->escapeString($functionDef->getNamespacedName()); ?>", ZEND_FN(<?php echo $zif_name; ?>), arginfo_<?php echo $zif_name; ?>)
<?php } else { ?>
    ZEND_FE(<?php echo $zif_name; ?>, arginfo_<?php echo $zif_name; ?>)
<?php } ?>
<?php }?>
    ZEND_FE_END
};
// clang-format on

PHP_MINIT_FUNCTION(<?php echo $this->getModuleName(); ?>) {
// class/interface class entries
<?php
foreach ($this->classCeList as $ce) {
    $info = $this->classCeInfo[$ce] ?? $this->getInternalCeInfo($ce);
    ?>
    <?php echo $ce; ?> = <?php echo $info['func']; ?>(<?php echo $info['args']; ?>);
<?php } ?>

// register symbols
<?php
foreach ($this->registerSymbols as $registerSymbolFn) {
    ?>
    <?php echo $registerSymbolFn; ?>(module_number);
<?php } ?>

    return SUCCESS;
}

void php_app_init() {
    // register constants
<?php
foreach ($this->nativeConstants as $name => $const) {
    ?>
    <?php echo $name; ?> = <?php echo $const->value; ?>;
    php::define("<?php echo $this->escapeString($const->name); ?>", <?php echo $name; ?>);
<?php } ?>

    // global vars
<?php
foreach ($this->globalVars as $name => $type) {
    ?>
    php::initGlobal("<?php echo $name; ?>", <?php echo $name; ?>);
<?php } ?>

    // property offset
<?php
foreach ($this->classes as $classDef) {
    foreach ($classDef->properties as $propertyDef) {
        ?>
    <?php echo Translator::PREFIX . $this->getPropertyOffset($propertyDef->name, $classDef->name, $classDef->namespace); ?> = php::getPropertyOffset("<?php echo $classDef->getNamespacedName(false); ?>", "<?php echo $propertyDef->name; ?>");
    <?php
    }
}
?>
}

void php_app_clean() {
<?php
foreach ($this->globalVars as $name => $type) {
    ?>
    <?php echo $name; ?>.unset();
    php::unsetGlobal("<?php echo $name; ?>");
<?php } ?>
<?php
foreach ($this->nativeConstants as $name => $const) {
    if ($const->type !== Translator::TYPE_VAR) {
        continue;
    }
    ?>
    <?php echo $name; ?>.unset();
<?php } ?>
}

PHP_RINIT_FUNCTION(<?php echo $this->getModuleName(); ?>) {
    php::request_init();
    php_app_init();

<?php if ($this->buildMode === 'bin') { ?>
<?php if (count($this->functions['main']->argInfoList) == 2) { ?>
    php::eval("global $argc, $argv; main($argc, $argv);");
<?php } else { ?>
    php::eval("main();");
<?php } ?>
<?php } ?>

    return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(<?php echo $this->getModuleName(); ?>) {
    php_app_clean();
    php::request_shutdown();
    return SUCCESS;
}

zend_module_entry <?php echo $this->getModuleName(); ?>_module_entry = {
    STANDARD_MODULE_HEADER,
    "<?php echo $this->getModuleName(); ?>",
    ext_functions,
    PHP_MINIT(<?php echo $this->getModuleName(); ?>),
    nullptr,
    PHP_RINIT(<?php echo $this->getModuleName(); ?>),
    PHP_RSHUTDOWN(<?php echo $this->getModuleName(); ?>),
    nullptr,
    nullptr,
    STANDARD_MODULE_PROPERTIES,
};

<?php if ($this->buildMode === 'ext') { ?>
ZEND_GET_MODULE(<?php echo $this->getModuleName(); ?>);
<?php } else { ?>
zend_module_entry *<?php echo Translator::PREFIX . 'embed_'; ?>get_module() {
    return &<?php echo $this->getModuleName(); ?>_module_entry;
}
<?php } ?>