<?php

namespace PhelixJuma\DataTransformer\Utils;


class UnitConverter
{
    public function __construct()
    {
    }

    /**
     * @param $conversionTable
     * @param $quantity
     * @param $from_unit
     * @param $to_unit
     * @return mixed
     */
    public static function convert($conversionTable, $quantity, $from_unit, $to_unit, $invertFactor=false): mixed
    {
        if (empty($quantity)) {
            return $quantity;
        }

        // Direct conversion
        foreach ($conversionTable as $conversion) {

            if ($conversion['from'] == $from_unit && $conversion['to'] == $to_unit) {

                $factor = 1;
                if (!empty($conversion['factor']) && $conversion['factor'] > 0) {
                    $factor = floatval($conversion['factor']);
                    if ($invertFactor) {
                        $factor = 1/$factor;
                    }
                }

                return ceil($quantity * $factor);
            }
        }

        // Inverse conversion
        foreach ($conversionTable as $conversion) {

            if ($conversion['from'] == $to_unit && $conversion['to'] == $from_unit) {

                $factor = 1;
                if (!empty($conversion['factor']) && $conversion['factor'] > 0) {
                    $factor = floatval($conversion['factor']);
                    if ($invertFactor) {
                        $factor = 1/$factor;
                    }
                }

                return ceil($quantity / $factor);
            }
        }
        return $quantity;
    }

    /**
     * @param $data
     * @param $items
     * @param $conversionTable
     * @param $quantity
     * @param $fromUnit
     * @param $toUnit
     * @param $outputPath
     * @return array
     */
    public static function convert_multiple($data, $items, $conversionTable, $quantity, $fromUnit, $toUnit, $invertFactor, $outputPath): array
    {
        if (isset($conversionTable['path'])) {
            $conversionTable = PathResolver::getValueByPath($data, $conversionTable['path']);
        }

        $items = isset($items['path']) ? PathResolver::getValueByPath($data, $items['path']) : $items;

        array_walk($items, function (&$item, $key) use($conversionTable, $quantity, $fromUnit, $toUnit, $invertFactor, $outputPath) {

            $conversionTable = isset($conversionTable['in_item_path']) ? PathResolver::getValueByPath($item, $conversionTable['in_item_path']) : $conversionTable;
            $quantity = isset($quantity['in_item_path']) ? PathResolver::getValueByPath($item, $quantity['in_item_path']) : $quantity;
            $fromUnit = isset($fromUnit['in_item_path']) ? PathResolver::getValueByPath($item, $fromUnit['in_item_path']) : $fromUnit;
            $toUnit = isset($toUnit['in_item_path']) ? PathResolver::getValueByPath($item, $toUnit['in_item_path']) : $toUnit;

            $convertedQuantity = self::convert($conversionTable, $quantity, $fromUnit, $toUnit, $invertFactor);

            PathResolver::setValueByPath($item,  $outputPath, [
                "original_value" => $quantity,
                "converted_value" => $convertedQuantity
            ]);

        });

        return $items;
    }
}


