<?php

namespace PhelixJuma\GUIFlow\Utils;

use ArrayJoin\Builder;
use ArrayJoin\On;
use FuzzyWuzzy\Fuzz;
use FuzzyWuzzy\Process;
use PhelixJuma\GUIFlow\Actions\FunctionAction;
use PhelixJuma\GUIFlow\Workflow;
use PhelixJuma\GUIFlow\Exceptions\UnknownOperatorException;

class DataValidator
{

    /**
     * @param $data
     * @param $quantityPath
     * @param $unitPricePath
     * @param $totalPricePath
     * @return mixed
     */
    public static function validateAndCorrectQuantityUsingPrice($data, $quantityPath, $unitPricePath, $totalPricePath)
    {
        // We first check where quantity is empty
        foreach ($data as &$d) {

            $quantity = PathResolver::getValueByPath($d, $quantityPath);
            $unitPrice = PathResolver::getValueByPath($d, $unitPricePath);
            $totalPrice = PathResolver::getValueByPath($d, $totalPricePath);

            $quantityValid = true; // assume quantity is valid, by default
            $d['quantity_validation'] = [
                'is_corrected' => false,
                'corrections'   => []
            ];

            if ($quantity == 0 || empty($quantity) || !is_numeric($quantity)) {
                // Quantity is either 0, empty or not a number. We need to validate and fix
                $quantityValid = false;

                // We attempt to correct
                if (!empty($unitPrice) && !empty($totalPrice) && $unitPrice > 0) {
                    $quantity = $totalPrice / $unitPrice;
                    PathResolver::setValueByPath($d, $quantityPath, $quantity);
                    $d['quantity_validation']['is_corrected'] = true;

                    $d['quantity_validation']['corrections'] = [
                        'quantity'      => $quantity,
                        'unit_price'    => null,
                        'total_price'   =>null
                    ];

                }

            } else {
                // quantity is a valid number. We validate against unit price and total price, if given
                if (!empty($unitPrice) && !empty($totalPrice)) {

                    $quantityValid = $quantity * $unitPrice == $totalPrice;

                    if (!$quantityValid) {

                        if ($unitPrice > $totalPrice) {
                            // We know unit price is wrong. So we correct it
                            $corrections = [$quantity, ($totalPrice/$quantity), $totalPrice];
                        } else {
                            // We need to know which of the three has an error.
                            $corrections = self::checkCorrections($quantity, $unitPrice, $totalPrice);
                        }

                        if (!isset($corrections['error'])) {

                            PathResolver::setValueByPath($d, $quantityPath, $corrections[0]);
                            PathResolver::setValueByPath($d, $unitPricePath, $corrections[1]);
                            PathResolver::setValueByPath($d, $totalPricePath, $corrections[2]);

                            $d['quantity_validation']['is_corrected'] = true;

                            $d['quantity_validation']['corrections'] = [
                                'quantity'      => $corrections[0],
                                'unit_price'    => $corrections[1],
                                'total_price'   => $corrections[2]
                            ];
                        }

                    }

                }
            }
            // We set the validity status
            $d['quantity_validation']['is_valid'] = $quantityValid;

        }
        return $data;
    }

    private static function isCorrect($quantity, $unitPrice, $totalPrice) {
        return $totalPrice == $unitPrice * $quantity && $unitPrice <= $totalPrice;
    }

    private static function correctCommonMistakes($value) {
        // Mapping common OCR misreadings and potential decimal issues
        $mappings = [
            '0' => ['8', '6'],   // 0 might be misread as 8 or 6
            '1' => ['7'],        // 1 might be misread as 7
            '3' => ['8'],        // 3 might be misread as 8
            '6' => ['8', '0'],   // 6 might be misread as 8 or 0
            '8' => ['0', '3', '6'], // 8 might be misread as 0, 3, or 6
        ];

        $possibleValues = [$value];
        $strVal = strval($value);

        // Handling potential decimal misinterpretation
        if (substr($strVal, -2) === '00') {
            $coreValue = intval(substr($strVal, 0, -2));
            $possibleValues[] = $coreValue; // Example: 300 to 3.00
        }

        // Apply common OCR misreadings and decimal errors
        for ($i = 0; $i < strlen($strVal); $i++) {
            $digit = $strVal[$i];
            if (array_key_exists($digit, $mappings)) {
                foreach ($mappings[$digit] as $replacement) {
                    $newStrVal = substr_replace($strVal, $replacement, $i, 1);
                    $possibleValues[] = intval($newStrVal);

                    // Additionally check for decimal placement errors with the new digit
                    if (substr($newStrVal, -2) === '00') {
                        $coreValue = intval(substr($newStrVal, 0, -2));
                        $possibleValues[] = $coreValue;
                    }
                }
            }
        }

        return $possibleValues;
    }

    private static function checkCorrections($quantity, $unitPrice, $totalPrice) {
        if (self::isCorrect($quantity, $unitPrice, $totalPrice)) {
            return array($quantity, $unitPrice, $totalPrice);
        }

        $values = array('quantity' => $quantity, 'unitPrice' => $unitPrice, 'totalPrice' => $totalPrice);
        $results = [];

        foreach ($values as $key => $value) {
            $correctedValues = self::correctCommonMistakes($value);

            foreach ($correctedValues as $correctedValue) {
                $newValues = array_merge($values, array($key => $correctedValue));
                if (self::isCorrect($newValues['quantity'], $newValues['unitPrice'], $newValues['totalPrice'])) {
                    $results[] = array($newValues['quantity'], $newValues['unitPrice'], $newValues['totalPrice']);
                }
            }
        }

        if (empty($results)) {
            return array('error' => 'No valid correction found', 'quantity' => $quantity, 'unitPrice' => $unitPrice, 'totalPrice' => $totalPrice);
        } else {
            // Return the first valid result found
            return $results[0];
        }
    }

}
