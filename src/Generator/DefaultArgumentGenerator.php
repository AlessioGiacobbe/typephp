<?php
/**
 * This file is part of TypePHP.
 *
 * Generates C++ helpers for defaults that require runtime initialization.
 */

namespace TypePhp\Generator;

use TypePhp\ArgInfo;
use TypePhp\Entity\ArrayInitPlan;
use TypePhp\Entity\FunctionDef;

trait DefaultArgumentGenerator
{
    protected function getDefaultArgumentType(ArgInfo $argInfo): string
    {
        $type = $argInfo->type;
        if ($type === self::TYPE_STREAM || $type === self::TYPE_BOX) {
            return self::TYPE_VAR;
        }
        return $type;
    }

    protected function getDefaultArgumentHelperName(FunctionDef $func, ArgInfo $argInfo): string
    {
        return self::PREFIX . 'default_arg_' . $func->name . '_' . $argInfo->name;
    }

    protected function genDefaultArgumentExpr(FunctionDef $func, ArgInfo $argInfo): string
    {
        if (!$argInfo->arrayInitPlan || !$argInfo->arrayInitPlan->requiresRuntimeInit()) {
            return $argInfo->default;
        }

        return $this->getDefaultArgumentHelperName($func, $argInfo) . '()';
    }

    protected function wrapArrayInitPlan(ArrayInitPlan $plan, string $body): string
    {
        return "do {\n" . $plan->init . $body . $plan->clean . "} while (0);\n";
    }

    protected function genDefaultArgumentHelpers(): string
    {
        $code = '';
        foreach ($this->functions as $func) {
            foreach ($func->argInfoList as $argInfo) {
                $plan = $argInfo->arrayInitPlan;
                if (!$plan || !$plan->requiresRuntimeInit()) {
                    continue;
                }

                $type = $this->getDefaultArgumentType($argInfo);
                $helper = $this->getDefaultArgumentHelperName($func, $argInfo);
                $code .= 'static inline ' . $type . ' ' . $helper . "() {\n";
                $code .= $plan->init;
                if ($plan->clean) {
                    $code .= $type . ' retval = ' . $plan->expr . ';' . PHP_EOL;
                    $code .= $plan->clean;
                    $code .= 'return retval;' . PHP_EOL;
                } else {
                    $code .= 'return ' . $plan->expr . ';' . PHP_EOL;
                }
                $code .= '}' . PHP_EOL;
            }
        }

        return $code ? $code . PHP_EOL : '';
    }
}

