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

            if ($quantity == 0 || empty($quantity) || !is_numeric($quantity)) {
                // Quantity is either 0, empty or not a number. We need to validate and fix

                // We attempt to correct
                if (!empty($unitPrice) && !empty($totalPrice) && $unitPrice > 0) {
                    $quantity = round($totalPrice / $unitPrice, 1);
                    PathResolver::setValueByPath($d, $quantityPath, $quantity);
                }

            } else {
                // quantity is a valid number. We validate against unit price and total price, if given
                if (!empty($unitPrice) && !empty($totalPrice)) {

                    $quantityValid = $quantity * $unitPrice == $totalPrice;

                    if (!$quantityValid) {

                        if ($unitPrice > $totalPrice) {
                            // We know unit price is wrong. So we correct it
                            $corrections = [$quantity, round($totalPrice/$quantity, 1), $totalPrice];
                        } elseif ($unitPrice == $totalPrice) {
                            // We know quantity is wrong since it's supposed to be 1
                            $corrections = [1, $unitPrice, $totalPrice];
                        } else {
                            // We need to know which of the three has an error.
                            $corrections = self::checkCorrections($quantity, $unitPrice, $totalPrice);
                        }

                        if (!isset($corrections['error'])) {

                            PathResolver::setValueByPath($d, $quantityPath, $corrections[0]);
                            PathResolver::setValueByPath($d, $unitPricePath, $corrections[1]);
                            PathResolver::setValueByPath($d, $totalPricePath, $corrections[2]);
                        }

                    }

                }
            }
        }
        return $data;
    }

    private static function isCorrect($quantity, $unitPrice, $totalPrice) {
        return round($totalPrice,1) == round($unitPrice,1) * round($quantity,1) && $unitPrice <= $totalPrice;
    }

    private static function correctCommonMistakes($value) {
        // Mapping common OCR misreadings and potential decimal issues
        $mappings = [
            '0' => ['8', '6'],   // 0 might be misread as 8 or 6
            '1' => ['7'],        // 1 might be misread as 7
            '3' => ['8', '7'],        // 3 might be misread as 8, 7
            '6' => ['8', '0'],   // 6 might be misread as 8 or 0
            '7' => ['1', '3'], // 8 might be misread as 0, 3, or 6
            '8' => ['0', '3', '6'], // 8 might be misread as 0, 3, or 6
        ];

        $possibleValues = [$value];
        $strVal = strval($value);

        // Handle 1 at the end of a number
        if ($strVal != '1' && substr($strVal, -1) === '1') {
            $coreValue = intval(substr($strVal, 0, -1));
            $possibleValues[] = $coreValue; // Example: 300 to 3.00
        }

        // Handling potential decimal misinterpretation
        if (substr($strVal, -2) === '00') {

            $coreValue = intval(substr($strVal, 0, -2));
            $possibleValues[] = $coreValue; // Example: 11 to 1

            // We get more possible values for the core value
            $possibleValues = array_merge($possibleValues,self::getPossibleValues($coreValue));

            // we add handling of a stray 1 after the removal
            $coreValueStr = (string)$coreValue;
            if ($coreValueStr != '1' && substr($coreValueStr, -1) === '1') {
                $coreValue = intval(substr($coreValueStr, 0, -1));
                $possibleValues[] = $coreValue; // Example: 1100 to 1
            }

        }

        // Apply common OCR misreadings and decimal errors
        for ($i = 0; $i < strlen($strVal); $i++) {
            $digit = $strVal[$i];
            if (array_key_exists($digit, $mappings)) {
                foreach ($mappings[$digit] as $replacement) {
                    $newStrVal = substr_replace($strVal, $replacement, $i, 1);
                    $possibleValues[] = intval($newStrVal);

                    // We get more possible values for the core value
                    $possibleValues = array_merge($possibleValues,self::getPossibleValues($newStrVal));

                    // Additionally check for decimal placement errors with the new digit
                    if (substr($newStrVal, -3) === '000') {
                        $coreValue = intval(substr($newStrVal, 0, -3));
                        $possibleValues[] = $coreValue;

                        // We get more possible values for the core value
                        $possibleValues = array_merge($possibleValues,self::getPossibleValues($coreValue));

                    }
                    if (substr($newStrVal, -2) === '00') {
                        $coreValue = intval(substr($newStrVal, 0, -2));
                        $possibleValues[] = $coreValue;

                        // We get more possible values for the core value
                        $possibleValues = array_merge($possibleValues,self::getPossibleValues($coreValue));

                    }
                    if (substr($newStrVal, -1) === '0') {
                        $coreValue = intval(substr($newStrVal, 0, -1));
                        $possibleValues[] = $coreValue;

                        // We get more possible values for the core value
                        $possibleValues = array_merge($possibleValues,self::getPossibleValues($coreValue));

                    }
                }
            }
        }
        return $possibleValues;
    }

    /**
     * @param $value
     * @return array
     */
    private static function getPossibleValues($value) {

        /**
         * Part 2: Check for digit variations
         */
        $strVal = strval($value);
        $digits = str_split('0123456789');

        $possibleValues = [];

        // Try removing each digit
        for ($i = 0; $i < strlen($strVal); $i++) {
            $possibleValues[] = intval(substr_replace($strVal, '', $i, 1));
        }

        // Try changing each digit to each possible digit
        for ($i = 0; $i < strlen($strVal); $i++) {
            foreach ($digits as $digit) {
                if ($digit != $strVal[$i]) {
                    $possibleValues[] = intval(substr_replace($strVal, $digit, $i, 1));
                }
            }
        }

        // Try adding each digit at each position
        for ($i = 0; $i <= strlen($strVal); $i++) {
            foreach ($digits as $digit) {
                $possibleValues[] = intval(substr($strVal, 0, $i) . $digit . substr($strVal, $i));
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

            if (!str_contains(strval($value), '.')) {
                /**
                 * Part 1: Correct common mistakes
                 */
                $correctedValues = self::correctCommonMistakes($value);

                foreach ($correctedValues as $correctedValue) {
                    $newValues = array_merge($values, array($key => $correctedValue));
                    if (self::isCorrect($newValues['quantity'], $newValues['unitPrice'], $newValues['totalPrice'])) {
                        return self::formatResponse(array($newValues['quantity'], $newValues['unitPrice'], $newValues['totalPrice']));
                    }
                }

                /**
                 * Part 2: Check for digit variations
                 */

                $possibleValues = self::getPossibleValues($value);

                foreach ($possibleValues as $possibleValue) {
                    $newValues = array_merge($values, array($key => $possibleValue));
                    if (self::isCorrect($newValues['quantity'], $newValues['unitPrice'], $newValues['totalPrice'])) {
                        return self::formatResponse(array($newValues['quantity'], $newValues['unitPrice'], $newValues['totalPrice']));
                    }
                }
            }
        }

        return array('error' => 'No valid correction found', 'quantity' => $quantity, 'unitPrice' => $unitPrice, 'totalPrice' => $totalPrice);
    }

    /**
     * @param $response
     * @return mixed
     */
    private static function formatResponse($response) {
        array_walk($response, function (&$v, $k) {
            $v = round($v, 1);
        });
        return $response;
    }

}