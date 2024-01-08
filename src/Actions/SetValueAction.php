<?php

namespace PhelixJuma\DataTransformer\Actions;

use PhelixJuma\DataTransformer\DataTransformer;
use PhelixJuma\DataTransformer\Utils\PathResolver;

class SetValueAction implements ActionInterface
{
    private $path;
    private $value;
    private $valueFromField;
    private $valueMapping;
    private $targetPath;
    private $conditionalValue;

    /**
     * @param $path
     * @param $value
     * @param $valueFromField
     * @param $valueMapping
     * @param $conditionalValue
     * @param $newField
     */
    public function __construct($path, $value = null, $valueFromField = null, $valueMapping = null, $conditionalValue = null, $newField=null)
    {
        $this->path = $path;
        $this->value = $value;
        $this->valueFromField = $valueFromField;
        $this->valueMapping = $valueMapping;
        $this->conditionalValue = $conditionalValue;
        $this->targetPath = !empty($newField) ? $newField : $this->path;
    }

    public function execute(&$data)
    {

        // Get all values from the path.
        $currentValues = PathResolver::getValueByPath($data, $this->path);

        // Determine the value to map with.
        $valueToSet = $this->value;
        if ($this->valueFromField !== null) {
            $valueToSet = PathResolver::getValueByPath($data, $this->valueFromField);
        }
        $newValue = "";

        if (is_array($currentValues)) {
            foreach ($currentValues as $index => $currentValue) {

                $value = is_array($valueToSet) ? $valueToSet[$index] : $valueToSet;

                // check if value-mapping
                if (!empty($this->valueMapping) && isset($this->valueMapping[$value])) {
                    $newValue = $this->valueMapping[$value];
                }
                // We set conditional values, if set.
                elseif(!empty($this->conditionalValue)) {
                    $newValue = $currentValue;
                    foreach ($this->conditionalValue as $conditionV) {
                        // We check if the condition is a pass
                        if (DataTransformer::evaluateCondition($currentValue, $conditionV['condition'], true)) {
                            // We set the value
                            if (!empty($conditionV['valueFromField'])) {
                                $valueFromField = PathResolver::getValueByPath($data, $conditionV['valueFromField']);
                                $newValue = is_array($valueFromField) ? $valueFromField[$index] : $valueFromField;
                            } else {
                                $newValue = $conditionV['value'];
                            }
                        }
                    }
                }
                // We set from value or valueFromField
                else {
                    $newValue = $valueToSet;
                }

                $targetPath = str_replace('*', $index, $this->targetPath);
                PathResolver::setValueByPath($data, $targetPath, $newValue);

            }
        } else {
            // This is for non-wildcard paths.

            // check if value-mapping
            if (!empty($this->valueMapping) && isset($this->valueMapping[$valueToSet])) {
                $newValue = $this->valueMapping[$valueToSet];
            }
            // We set conditional values, if set.
            elseif(!empty($this->conditionalValue)) {
                foreach ($this->conditionalValue as $conditionV) {
                    // We check if the condition is a pass
                    if (DataTransformer::evaluateCondition($valueToSet, $conditionV['condition'], true)) {
                        // We set the value
                        if (!empty($conditionV['valueFromField'])) {
                            $newValue = PathResolver::getValueByPath($data, $conditionV['valueFromField']);
                        } else {
                            $newValue = $conditionV['value'];
                        }
                    }
                }
            }
            // We set from value or valueFromField
            else {
                $newValue = $valueToSet;
            }

            PathResolver::setValueByPath($data, $this->targetPath, $newValue);
        }
    }
}
