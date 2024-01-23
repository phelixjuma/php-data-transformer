<?php

namespace PhelixJuma\DataTransformer\Utils;

use ArrayJoin\Builder;
use ArrayJoin\On;
use FuzzyWuzzy\Fuzz;
use FuzzyWuzzy\Process;
use PhelixJuma\DataTransformer\Actions\FunctionAction;
use PhelixJuma\DataTransformer\Conditions\SimpleCondition;
use PhelixJuma\DataTransformer\DataTransformer;
use PhelixJuma\DataTransformer\Exceptions\UnknownOperatorException;

class Utils
{

    /**
     * @param $array
     * @param $key
     * @return mixed
     */
    public static function sortMultiAssocArrayByKey($array, $key, $order='asc')
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
    public static function format_date($input, $format)
    {
        // Set the timezone of the object to UTC
        $timezone  = new \DateTimeZone('UTC');

        //return date($format, $date);
        if (is_numeric($input)) {
            $date = new \DateTime("@$input", $timezone);
        } else {
            // Otherwise, try to parse the string directly
            try {
                $date = !empty($input) ? new \DateTime($input, $timezone) : "";
            } catch (\Exception $e) {
                // If an exception is caught, the date format is not recognized
                return "Invalid date format: " . $e->getMessage();
            }
        }

        // Format the date
        return $date->format($format);
    }

    public static function prepend($data, $stringsToPrepend, $separator = " ", $condition = null)
    {
        $modifiedSeparator = " $separator ";
        $strings = implode($modifiedSeparator, $stringsToPrepend);

        // If the data is an array, apply prepend recursively to each element
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::prepend($value, $stringsToPrepend, $modifiedSeparator, $condition);
            }
            return $data;
        }

        // If it's not an array, apply the prepend logic to the string
        if (empty($condition) || DataTransformer::evaluateCondition($data, $condition, true)) {
            return self::removeExtraSpaces($strings . $modifiedSeparator . $data);
        }

        return $data;
    }

    public static function append($data, $stringsToAppend, $separator = " ", $useDataAsPathValue = true, $valueKey=null, $condition = null)
    {

        $modifiedSeparator = " $separator ";
        $strings = implode($modifiedSeparator, $stringsToAppend);

        // If the data is an array, apply prepend recursively to each element
        if (is_array($data) && !self::isObject($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::append($value, $stringsToAppend, $modifiedSeparator, $useDataAsPathValue, $valueKey, $condition);
            }

            return $data;
        }

        // If it's not an array, apply the prepend logic to the string
        if (empty($condition) || DataTransformer::evaluateCondition($data, $condition, $useDataAsPathValue)) {

            if (self::isObject($data) && !empty($valueKey)) {

                $data[$valueKey] = self::removeExtraSpaces($data[$valueKey] . $modifiedSeparator . $strings);

                return $data;
            }
            return self::removeExtraSpaces($data . $modifiedSeparator . $strings);
        }

        return $data;
    }

    /**
     * @param $string
     * @param $enclosure
     * @return string
     */
    private static function enclose($string, $enclosure) {
        return match ($enclosure) {
            'brackets' => "($string)",
            'square brackets' => "[$string]",
            'curly brackets' => '{'.$string.'}',
            'forward strokes' => "/$string/",
            'backward strokes' => "\\$string\\",
            'double quotes' => '"'.$string.'"',
            'single quotes' => "'".$string."'",
            default => $string,
        };
    }

    /**
     * @param $masterData
     * @param $definitions
     * @return array|mixed|null
     */
    private static function getValues($masterData, $definitions): mixed
    {

        if (!is_array($definitions)) {
            return $definitions;
        }

        if (isset($definitions['path'])) {

            return PathResolver::getValueByPath($masterData, $definitions['path']);
        }

        foreach ($definitions as &$definition) {
            $definition = self::getValues($masterData, $definition);
        }
        return $definitions;
    }

    /**
     * @param $strings
     * @param $separator
     * @param $enclosure
     * @return array|string|string[]|null
     */
    public static function concat($data, $dataToAdd, $separator = " ", $enclosure="")
    {

        $stringsToAdd = self::getValues($data, $dataToAdd);

        $separator = " $separator "; // add spaces to the separator
        if (empty($enclosure)) {
            return self::removeExtraSpaces(implode($separator, $stringsToAdd));
        } else {
            $response = "";
            $numberOfItems = sizeof($stringsToAdd);

            for ($i = 0; $i < $numberOfItems; $i++) {

                $data = trim($stringsToAdd[$i]);

                if (!empty($data)) {
                    if ($i == 0) {
                        $response .= $separator.$data;
                    } else {
                        $response .= $separator.self::enclose($data, $enclosure);
                    }
                }
            }
            return self::removeExtraSpaces($response);
        }
    }

    /**
     * @param array $data
     * @param array $fields
     * @param $newField
     * @return array
     */
    public static function concat_multi_array_assoc($data, $fields, $newField, $separator = " ", $enclosure="")
    {

        $separator = " $separator "; // add spaces to the separator

        if (is_array($data)) {
            array_walk($data, function (&$value, $key) use($fields, $newField, $separator, $enclosure) {
                $dataToConcat = array_flip($fields);
                foreach ($value as $key => $v) {
                    if (in_array($key, $fields)) {
                        $dataToConcat[$key] = $v;
                    }
                }
                $value[$newField] = self::concat([], array_values($dataToConcat), $separator, $enclosure);
            });
        }
        return $data;
    }

    /**
     * @param $data
     * @param $pattern
     * @param $replacement
     * @return array|string|string[]|null
     */
    public static function custom_preg_replace($data, $pattern, $replacement)
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
    public static function assoc_array_sum_if($data, $conditionField, $conditionOperator, $conditionValue, $conditionSimilarityThreshold = 80, $sumField = null)
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
     * @param $operations
     * @return mixed
     * @throws UnknownOperatorException
     */
    public static function assoc_array_set_if($data, $operations = [])
    {

        if (!empty($operations)) {
            foreach ($operations as $operation) {
//                $setField = $operation['setField'];
//                $setValue,
//                $conditionField,
//                $conditionOperator,
//                $conditionValue,
//                $conditionSimilarityThreshold
                extract($operation);

                $operation_value = isset($operation_value['path']) ? PathResolver::getValueByPath($data, $operation_value['path']) : $operation_value;

                if (!empty($data) && is_array($data)) {
                    foreach ($data as &$d) {
                        if (SimpleCondition::compare($d[$condition_field], $condition_operator, $condition_value, $condition_similarity_threshold ?? null)) {
                            $d[$operation_field] = $operation_value;
                        }
                    }
                }

            }
        }

        return $data;
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
    public static function assoc_array_find($data, $conditionField, $conditionOperator, $conditionValue, $conditionSimilarityThreshold = 80, $conditionSimilarityTokenize= false, $returnKey = null)
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

            // Set the timezone of the object to UTC
            $timezone  = new \DateTimeZone('UTC');

            if (is_array($data)) {
                foreach ($data as $datum) {
                    $response[] = (new \DateTime($datum, $timezone))->$method(new \DateInterval("P{$days}D"))->format($format);
                }
            } else {
                $response = (new \DateTime($data, $timezone))->$method(new \DateInterval("P{$days}D"))->format($format);
            }

        } catch (\Exception $e) {
        }
        return $response;
    }

    /**
     * @param $startDateString
     * @param $endDateString
     * @param $period
     * @return null
     */
    public static function date_diff($startDateString, $endDateString, $period="d") {

        $response = null;

        try {

            // Set the timezone of the object to UTC
            $timezone  = new \DateTimeZone('UTC');

            $startDate = new \DateTime($startDateString, $timezone);
            $endDate = new \DateTime($endDateString, $timezone);

            $diff = $startDate->diff($endDate);

            switch ($period) {
                case  'y':
                    $response = $diff->y;
                    break;
                case 'm':
                    $response = ($diff->y*12) + $diff->m;
                    break;
                case 'd':
                    $response = (365 * $diff->y) + (30 * $diff->m) + $diff->d;
                    break;
                case 'h':
                    $response = (24 * 365 * $diff->y) + (24 * 30 * $diff->m) + (24 * $diff->d) + $diff->h;
                    break;
                case 'i':
                    $response = (60 * 24 * 365 * $diff->y) + (60 * 24 * 30 * $diff->m) + (60 * 24 * $diff->d)  + (60 * $diff->h) + $diff->i;
                    break;
                case 's':
                    $response = (60 * 60 * 24 * 365 * $diff->y) + (60 * 60 * 24 * 30 * $diff->m) + (60 * 60 * 24 * $diff->d)  + (60 * 60 * $diff->h) + $diff->s;
                    break;
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

            // Set the timezone of the object to UTC
            $timezone  = new \DateTimeZone('UTC');

            if (is_array($data)) {
                foreach ($data as $datum) {
                    $response[] = !empty($datum) ? (new \DateTime($datum, $timezone))->format($format) : "";
                }
            } else {
                $response = !empty($data) ? (new \DateTime($data, $timezone))->format($format) : "";
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
                    if ($oldKey != $newKey && array_key_exists($oldKey, $data)) {
                        $data[$newKey] = $data[$oldKey];
                        unset($data[$oldKey]);
                    }
                }
            } else {

                $size =  sizeof($data);
                for($index = 0; $index < $size; $index++) {
                    foreach ($keyMap as $oldKey => $newKey) {
                        if ($oldKey != $newKey && array_key_exists($oldKey, $data[$index])) {
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
    public static function cleanText($text)
    {
        // Convert text to lowercase
        $text = strtolower($text);

        // Remove URLs
        //$text = preg_replace('/https?:\/\/\S+/', '', $text);
        // Remove or replace special characters (excluding spaces)
        //$text = preg_replace('/[^a-z0-9\s]/', '', $text);

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
     * @param $text
     * @return array|string|string[]|null
     */
    public static function removeExtraSpaces($text) {
        if (!is_null($text)) {
            return preg_replace('/\s+/', ' ', trim($text));
        }
        return $text;
    }

    public static function remove_repeated_words($data) {

        if (is_array($data)) {
            foreach ($data as &$value) {
                $value = self::remove_repeated_words($value);
            }
        } else {
            $words = explode(' ', $data); // Split the string into words
            $seen = array();                // Array to track seen words in lowercase
            $result = array();              // Array to hold the final words

            foreach ($words as $word) {
                $lowercaseWord = strtolower($word); // Convert the word to lowercase for comparison
                if (!isset($seen[$lowercaseWord])) {
                    $seen[$lowercaseWord] = true;    // Mark the lowercase word as seen
                    $result[] = $word;               // Add the original word to the result
                }
            }

            return self::removeExtraSpaces(implode(' ', $result));   // Join the words back into a string
        }
        return $data;
    }

    public static function  custom_preg_escape($input) {

        // Define characters to escape
        $charsToEscape = ['/',"'", '"'];  // Add any other characters you'd like to escape

        // Escape each character
        foreach ($charsToEscape as $char) {
            $input = str_replace($char, '\\' . $char, $input);
        }

        return $input;
    }

    /**
     * @param $error
     * @return string
     */
    public static function getPregError($error) {
        return match ($error) {
            PREG_INTERNAL_ERROR => 'There was an internal error!',
            PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit was exhausted!',
            PREG_RECURSION_LIMIT_ERROR => 'Recursion limit was exhausted!',
            PREG_BAD_UTF8_ERROR => 'Bad UTF-8 error!',
            PREG_BAD_UTF8_OFFSET_ERROR => 'Bad UTF-8 offset error!',
            PREG_JIT_STACKLIMIT_ERROR => 'JIT stack limit error!',
            default => $error. 'Unknown error!',
        };
    }

    /**
     * @param $data
     * @param $transformFunction
     * @param $args
     * @param $targetKeys
     * @param $condition
     * @return mixed
     * @throws UnknownOperatorException
     */
    public static function transform_data($data, $transformFunction, $args = [], $targetKeys=[], $condition=[]) {

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

                $pattern = "/".self::custom_preg_escape($pattern)."/";
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
            'string_to_date_time' => function($data, $format="Y-m-d H:i:s", $pre_modifier="", $post_modifier="") {

                $timezone  = new \DateTimeZone('UTC');

                $getCurrentTime = function($timezone) {
                    return (new \DateTime("now", $timezone))->format("H:i:s"); // Returns the current time
                };

                $convertToDate = function($dateString, $format, $getCurrentTime, $timezone) {
                    // Check if time part is present by looking for a colon, which appears in time strings
                    if (!str_contains($dateString, ':')) {
                        // If no time part is present, append the current time
                        $dateString .= ' ' . $getCurrentTime($timezone);
                    }
                    return !empty($dateString) ? (new \DateTime($dateString, $timezone))->format($format) : "";
                };

                $date = null;
                if (is_array($data)) {
                    foreach ($data as $datum) {
                        $dateString = self::removeExtraSpaces("$pre_modifier $datum $post_modifier");
                        $date[] = $convertToDate($dateString, $format, $getCurrentTime, $timezone);
                    }
                } else {
                    $dateString = self::removeExtraSpaces("$pre_modifier $data $post_modifier");
                    $date = $convertToDate($dateString, $format, $getCurrentTime, $timezone);
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

                    $pattern = '/' . self::custom_preg_escape(self::full_unescape($search)) . '/'.$modifier;

                    $value = preg_replace($pattern, $replace, $value);

                    if (preg_last_error() !== PREG_NO_ERROR) {
                        //throw new \Exception("Preg Error: ".self::getPregError(preg_last_error()));
                    }
                }
                return self::removeExtraSpaces($value);
            }
        ];

        // Extract options

        if (is_array($data)) {
            foreach ($data as $key => $value) {

                if (empty($condition) || DataTransformer::evaluateCondition($value, $condition, true)) {

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
            }
        } elseif (empty($targetKeys)) {

            if (empty($condition) || DataTransformer::evaluateCondition($data, $condition, true)) {
                return isset($specialFunctions[$transformFunction])
                    ? $specialFunctions[$transformFunction]($data, ...$args)
                    : (!empty($args) ? $transformFunction($data, ...$args) : $transformFunction($data));
            }
        }

        return $data;
    }

    public static function removeKeysFromAssocArray($data, $keysToRemove)
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

    /**
     * @param $data
     * @param $leftData
     * @param $rightData
     * @param $join
     * @param $fields
     * @param $groupBy
     * @return array|null
     */
    public static function join($data, $leftData, $rightData, $join, $fields, $groupBy = null): ?array
    {

        if (isset($leftData['path'])) {
            $leftData = PathResolver::getValueByPath($data, $leftData['path']);
        }
        if (isset($rightData['path'])) {
            $rightData = PathResolver::getValueByPath($data, $rightData['path']);
        }

        $response = null;

        try {
            $instance = Builder::newInstance()
                ->select(...$fields)
                ->from($leftData, "left");

            if ($join['type'] == 'inner') {
                $instance->innerJoin($rightData, "right", new On($join['on']));
            } elseif ($join['type'] == 'left') {
                $instance->leftJoin($rightData, "right", new On($join['on']));
            } elseif ($join['type'] == 'right') {
                $instance->rightJoin($rightData, "right", new On($join['on']));
            }

            if (!empty($groupBy)) {
                $instance->groupBy($groupBy);
            }

            $instance->setFetchType(Builder::FETCH_TYPE_ARRAY);

            $response = $instance->execute();

        } catch (\Exception $e) {
            print "Exception: ".$e->getMessage();
        }

        return $response;
    }

}
