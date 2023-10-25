<?php

namespace PhelixJuma\DataTransformer\Utils;

use FuzzyWuzzy\Fuzz;
use FuzzyWuzzy\Process;
use PhelixJuma\DataTransformer\Actions\FunctionAction;
use PhelixJuma\DataTransformer\Conditions\SimpleCondition;
use PhelixJuma\DataTransformer\Exceptions\UnknownOperatorException;

class Utils
{

    /**
     * @param $array
     * @param $key
     * @return mixed
     */
    public static function sortMultiAssocArrayByKey($array, $key, $order='asc'): mixed
    {
        usort($array, function($a, $b) use ($key, $order) {
            if (isset($a[$key]) && isset($b[$key])) {
                $result = ($a[$key] < $b[$key]) ? -1 : (($a[$key] > $b[$key]) ? 1 : 0);
                return ($order === 'desc') ? -$result : $result;
            }
            return 0; // If the key doesn't exist in one of the arrays, consider them equal
        });
        return $array;
    }

    /**
     * @param $date
     * @param $format
     * @return string
     */
    public static function format_date($date, $format): string
    {
        return date($format, $date);
    }

    /**
     * @param $data
     * @param $stringsToAppend
     * @param $separator
     * @return array|string
     */
    public static function prepend($data, $stringsToAppend, $separator = " ")
    {
        $separator = " $separator ";
        $strings = implode($separator, $stringsToAppend);

        $newData = null;
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $newData[$key] = self::removeExtraSpaces($strings . $separator . $value);
            }
        } else {
            $newData = self::removeExtraSpaces($strings . $separator . $data);
        }
        return $newData;
    }

    public static function append($data, $stringsToAppend, $separator = " ")
    {
        $separator = " $separator ";
        $strings = implode($separator, $stringsToAppend);

        $newData = null;
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $newData[$key] = self::removeExtraSpaces($value. $separator. $strings);
            }
        } else {
            $newData = self::removeExtraSpaces($data. $separator. $strings);
        }
        return $newData;
    }

    /**
     * @param array $strings
     * @param $separator
     * @return string
     */
    public static function concat(array $strings, $separator = " "): string
    {
        $separator = " $separator "; // add spaces to the separator
        return self::removeExtraSpaces(implode($separator, $strings));
    }

    /**
     * @param array $data
     * @param array $fields
     * @param $newField
     * @return array
     */
    public static function concat_multi_array_assoc(array $data, array $fields, $newField, $separator = " "): array
    {

        $separator = " $separator "; // add spaces to the separator

        array_walk($data, function (&$value, $key) use($fields, $newField, $separator) {
            $dataToConcat = array_flip($fields);
            foreach ($value as $key => $v) {
                if (in_array($key, $fields)) {
                    $dataToConcat[$key] = $v;
                }
            }
            $value[$newField] = self::concat(array_values($dataToConcat), $separator);
        });

        return $data;
    }

    /**
     * @param $data
     * @param $pattern
     * @param $replacement
     * @return array|string|string[]|null
     */
    public static function custom_preg_replace($data, $pattern, $replacement): array|string|null
    {
        $newData = null;
        if (is_array($data)) {
            foreach ($data as $d) {
                $newData[] = preg_replace($pattern, $replacement, $d);
            }
        } else {
            $newData = preg_replace($pattern, $replacement, $data);
        }
        return $newData;
    }

    /**
     * @param $data
     * @param $conditionField
     * @param $conditionOperator
     * @param $conditionValue
     * @param $conditionSimilarityThreshold
     * @param $sumField
     * @return mixed
     * @throws UnknownOperatorException
     */
    public static function assoc_array_sum_if($data, $conditionField, $conditionOperator, $conditionValue, $conditionSimilarityThreshold = 80, $sumField = null): mixed
    {

        $sum = 0;

        if (!empty($data) && is_array($data)) {
            foreach ($data as $d) {
                if (SimpleCondition::compare($d[$conditionField], $conditionOperator, $conditionValue, $conditionSimilarityThreshold)) {
                    $sum += $d[$sumField];
                }
            }
        }
        return $sum;
    }

    /**
     * @param $data
     * @param $conditionField
     * @param $conditionOperator
     * @param $conditionValue
     * @param $conditionSimilarityThreshold
     * @param $conditionSimilarityTokenize
     * @param $returnKey
     * @return mixed
     * @throws UnknownOperatorException
     */
    public static function assoc_array_find($data, $conditionField, $conditionOperator, $conditionValue, $conditionSimilarityThreshold = 80, $conditionSimilarityTokenize= false, $returnKey = null): mixed
    {

        $response = null;

        if (!empty($data) && is_array($data)) {

            if (array_key_exists($conditionField, $data[0])) {
                foreach ($data as $d) {

                    if (SimpleCondition::compare($d[$conditionField], $conditionOperator, $conditionValue, $conditionSimilarityThreshold, $conditionSimilarityTokenize)) {
                        if (!empty($returnKey)) {
                            return $d[$returnKey];
                        }
                        return $d;
                    }
                }
            } else {
                foreach ($data as $d) {
                    $response[] = self::assoc_array_find($d, $conditionField, $conditionOperator, $conditionValue, $conditionSimilarityThreshold, $conditionSimilarityTokenize, $returnKey);
                }
            }
        }
        return $response;
    }

    /**
     * @param $data
     * @param $days
     * @param $operator
     * @param $format
     * @return array
     */
    public static function date_add_substract_days($data, $days, $operator, $format="Y-m-d") {

        $method = $operator == 'add' ? 'add' : 'sub';

        $response = null;

        try {

            if (is_array($data)) {
                foreach ($data as $datum) {
                    $response[] = (new \DateTime($datum))->$method(new \DateInterval("P{$days}D"))->format($format);
                }
            } else {
                $response = (new \DateTime($data))->$method(new \DateInterval("P{$days}D"))->format($format);
            }

        } catch (\Exception $e) {
        }
        return $response;
    }

    /**
     * @param $data
     * @param $format
     * @return array|string
     */
    public static function date_format($data, $format="Y-m-d") {

        $response = null;
        try {
            if (is_array($data)) {
                foreach ($data as $datum) {
                    $response[] = (new \DateTime($datum))->format($format);
                }
            } else {
                $response = (new \DateTime($data))->format($format);
            }
        } catch (\Exception $e) {

        }
        return $response;
    }

    /**
     * @param $data
     * @param $key
     * @return mixed|string
     */
    public static function get_from_object($data, $key) {

        if (array_key_exists($key, $data)) {
            return $data[$key];
        }
        return "";
    }

    /**
     * @param $data
     * @param $keyMap
     * @return mixed
     */
    public static function rename_object_keys(&$data, $keyMap) {

        if (is_array($data)) {
            if (self::isObject($data)) {
                foreach ($keyMap as $oldKey => $newKey) {
                    if (array_key_exists($oldKey, $data)) {
                        $data[$newKey] = $data[$oldKey];
                        unset($data[$oldKey]);
                    }
                }
            } else {

                $size =  sizeof($data);
                for($index = 0; $index < $size; $index++) {
                    foreach ($keyMap as $oldKey => $newKey) {
                        if (array_key_exists($oldKey, $data[$index])) {
                            $data[$index][$newKey] = $data[$index][$oldKey];
                            unset($data[$index][$oldKey]);
                        }
                    }
                }

            }
        }
        return $data;
    }

    /**
     * @param $text
     * @return string
     */
    public static function cleanText($text): string
    {
        // Convert text to lowercase
        $text = strtolower($text);

        // Remove URLs
        $text = preg_replace('/https?:\/\/\S+/', '', $text);

        // Remove or replace special characters (excluding spaces)
        $text = preg_replace('/[^a-z0-9\s]/', '', $text);

        // Remove extra spaces
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * @param $query
     * @param $choices
     * @return mixed|null
     */
    public static function fuzzy_extract_one($query, $choices, $minScore=50, $defaultChoice="", $fuzzyMethod = 'tokenSetRatio') {

        $fuzz = new Fuzz();
        $fuzzProcess = new Process();

        $isList = is_array($query);

        if (!$isList) {
            $query = [$query];
        }

        $extracted = [];

        if (!empty($query)) {
            foreach ($query as $key => $search) {

                // set default
                $extracted["$key-$search"] = $defaultChoice;

                $result = $fuzzProcess->extractOne($search, $choices, null, [$fuzz, $fuzzyMethod]);

                if (!empty($result)) {
                    $choice = $result[0];
                    $score = $result[1];

                    if ($score >= $minScore) {
                        $extracted["$key-$search"] = $choice;
                    }
                }
            }

            // We get the responses.
            $response = array_values($extracted);

            return !$isList ? $response[0] : $response;
        }
        return null;
    }

    public static function full_unescape($string) {
        return html_entity_decode(htmlspecialchars_decode($string, ENT_QUOTES), ENT_QUOTES);
    }

    /**
     * @param string $text
     * @return string
     */
    public static function removeExtraSpaces(string $text): string {
        return preg_replace('/\s+/', ' ', $text);
    }

    private static function  custom_preg_escape($input) {

        // Define characters to escape
        $charsToEscape = ['/',"'", '"'];  // Add any other characters you'd like to escape

        // Escape each character
        foreach ($charsToEscape as $char) {
            $input = str_replace($char, '\\' . $char, $input);
        }

        return $input;
    }
    public static function transform_data($data, $transformFunction, $args = [], $targetKeys=[]) {

        if (!in_array($transformFunction, FunctionAction::SUPPORTED_FUNCTIONS)) {
            throw new UnknownOperatorException();
        }

        $specialFunctions = [
            'str_replace' => function($subject, $search, $replacement) {
                if (!empty($replacement) && str_contains($subject, $replacement)) {
                    return self::removeExtraSpaces($subject);
                }
                return self::removeExtraSpaces(str_replace($search, $replacement, $subject));
            },
            'preg_replace' => function($subject, $pattern, $replacement, $addSpacer=true, $isCaseSensitive=false) {

                $pattern = "/$pattern/";
                if (!$isCaseSensitive) {
                    $pattern .= "i";
                }

                if (!preg_match($pattern, $subject)) {
                    return $subject;
                }

                // Add a spacer to replacement
                if ($addSpacer) {
                    $replacement = " $replacement ";
                }

                return self::removeExtraSpaces(preg_replace($pattern, $replacement, $subject));
            },
            'explode' => function($string, $separator,) {
                return explode($separator, $string);
            },
            'string_to_date_time' => function($data, $format="Y-m-d", $pre_modifier="", $post_modifier="") {
                $date = null;
                if (is_array($data)) {
                    foreach ($data as $datum) {
                        $dateString = self::removeExtraSpaces("$pre_modifier $datum $post_modifier");
                        $date[] = date($format, strtotime($dateString));
                    }
                } else {
                    $dateString = self::removeExtraSpaces("$pre_modifier $data $post_modifier");
                    $date = date($format, strtotime($dateString));
                }
                return $date;
            },
            'dictionary_mapper' => function($value, $mappings) {
                // Set keys to lower case
                $mappings = array_change_key_case($mappings, CASE_LOWER);
                return $mappings[strtolower($value)] ?? $value;
            },
            'regex_mapper' => function($value, $mappings, $isCaseSensitive = false) {
                $modifier = !$isCaseSensitive ? 'i' : '';

                foreach ($mappings as $search => $replace) {

                    if (empty($replace) || !str_contains($value, $replace)) {

                        $pattern = '/\b' . self::full_unescape($search) . '\b/'.$modifier;
                        $value = preg_replace($pattern, $replace, $value);
                    }
                }
                return self::removeExtraSpaces($value);
            }
        ];

        // Extract options

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = self::transform_data($value, $transformFunction, $args, $targetKeys);
                } else {
                    if (empty($targetKeys) || in_array($key, $targetKeys)) {
                        if (isset($specialFunctions[$transformFunction])) {
                            $data[$key] = $specialFunctions[$transformFunction]($value, ...$args);
                        } else {
                            $data[$key] = !empty($args) ? $transformFunction($value, ...$args) : $transformFunction($value);
                        }
                    }
                }
            }
        } elseif (empty($targetKeys)) {
            return isset($specialFunctions[$transformFunction])
                ? $specialFunctions[$transformFunction]($data, ...$args)
                : (!empty($args) ? $transformFunction($data, ...$args) : $transformFunction($data));
        }

        return $data;
    }

    public static function removeKeysFromAssocArray($data, $keysToRemove): array
    {
        return array_diff_key($data, array_flip($keysToRemove));
    }

    /**
     * An associative array in php - akin to objects in other lands
     * @param $value
     * @return bool
     */
    public static function isObject($value) {
        return (is_array($value) && array_keys($value) !== range(0, count($value) - 1));
    }

    /**
     * @param $obj
     * @param $prefix
     * @return array
     */
    public static function flattenObject($obj, $prefix = '') {

        $flattened = [];

        foreach ($obj as $key => $value) {
            $newKey = $prefix . $key;
            if (is_array($value) && array_values($value) !== $value) { // Associative array (dict in python)
                $nested = self::flattenObject($value, $newKey . '.');
                $flattened = array_merge($flattened, $nested);
            } elseif (is_array($value) && array_values($value) === $value) { // Indexed array (list in python)
                foreach ($value as $index => $item) {
                    $value[$index] = is_array($item) && array_values($item) !== $item ? self::flattenObject($item) : $item;
                }
                $flattened[$newKey] = $value;
            } else {
                $flattened[$newKey] = $value;
            }
        }

        return $flattened;
    }

    /**
     * @param $data
     * @param $prefix
     * @param $siblings
     * @return array
     */
    public static function expandList($data, $prefix = null, $siblings = []) {
        $expanded = [];

        if (is_array($data) && array_values($data) === $data) { // Indexed array
            foreach ($data as $item) {
                $expanded = array_merge($expanded, self::expandList($item, $prefix, $siblings));
            }
        } elseif (is_array($data)) { // Associative array
            $nonListValues = [];
            $listValues = [];
            foreach ($data as $key => $value) {
                $newKey = $prefix ? $prefix . '.' . $key : $key;
                if (is_array($value) && array_values($value) === $value) {
                    $listValues[$key] = $value;
                } else {
                    $nonListValues[$newKey] = $value;
                }
            }

            if (!empty($listValues)) {
                foreach ($listValues as $key => $value) {
                    $newKey = $prefix ? $prefix . '.' . $key : $key;
                    $expanded = array_merge($expanded, self::expandList($value, $newKey, array_merge($siblings, $nonListValues)));
                }
            } else {
                $expanded[] = array_merge($siblings, $nonListValues);
            }
        } else {
            $expanded[] = [$prefix => $data];
        }

        return $expanded;
    }

    /**
     * @param $data
     * @return array
     */
    public static function flattenAndExpand($data) {
        $flattenedData = self::flattenObject($data);
        return self::expandList($flattenedData);
    }

}
