<?php

namespace PhelixJuma\DataTransformer\Conditions;

use FuzzyWuzzy\Fuzz;
use PhelixJuma\DataTransformer\Exceptions\UnknownOperatorException;
use PhelixJuma\DataTransformer\Utils\PathResolver;
use PhelixJuma\DataTransformer\Utils\Utils;

class SimpleCondition implements ConditionInterface
{
    private $condition;
    private $pathResolver;

    public function __construct($condition, PathResolver $pathResolver)
    {
        $this->condition = $condition;
        $this->pathResolver = $pathResolver;
    }

    /**
     * @param $data
     * @return bool
     * @throws UnknownOperatorException
     */
    public function evaluate($data): bool
    {

        if ($this->condition == 'always') {
            return true;
        }

        if (isset($this->condition['path_value'])) {
            $pathValues = $this->condition['path_value'];
        } else {
            $pathValues = $this->pathResolver::getValueByPath($data, $this->condition['path']);
        }

        $operator = $this->condition['operator'];
        $conditionValue = $this->condition['value'] ?? null;

        if (isset($conditionValue['path'])) {
            $value = $this->pathResolver::getValueByPath($data, $conditionValue['path']);
        } else {
            $value = $conditionValue;
        }
        $similarityThreshold = $this->condition['similarity_threshold'] ?? null;

        // Handle wildcard paths
        if (is_array($pathValues) && !Utils::isObject($pathValues)) {
            foreach ($pathValues as $pathValue) {
                if (self::compare($pathValue, $operator, $value, $similarityThreshold)) {
                    return true; // Return true as soon as one match is found
                }
            }
            return false; // If no match is found, return false
        } else {
            return self::compare($pathValues, $operator, $value, $similarityThreshold);
        }
    }

    /**
     * @param $pathValue
     * @param $operator
     * @param $value
     * @return bool
     * @throws UnknownOperatorException
     */
    public static function compare($pathValue, $operator, $value, $similarityThreshold = null, $tokenizeSimilarity = false): bool
    {
        $fuzz = new Fuzz();

        // lowercase
        $pathValue = is_string($pathValue) ? Utils::cleanText($pathValue) : $pathValue;
        $value = is_string($value) ? Utils::cleanText($value)  : $value;

        // The existing switch case logic...
        switch ($operator) {
            case '==':
                return $pathValue == $value;
            case '!=':
                return $pathValue != $value;
            case 'gt':
                return $pathValue > $value;
            case 'gte':
                return $pathValue >= $value;
            case 'lt':
                return $pathValue < $value;
            case 'lte':
                return $pathValue <= $value;
            case 'contains':
                return !empty($pathValue) && !empty($value) && str_contains($pathValue, $value);
            case 'not contains':
                return empty($pathValue) || empty($value) || !str_contains($pathValue, $value);
            case 'exists':
                // For arrays, we remove empty values
                if (is_array($pathValue) && !Utils::isObject($pathValue)) {
                    $pathValue = array_filter($pathValue);
                }
                return $pathValue === 0 || !empty($pathValue);
            case 'not exists':
                // For arrays, we remove empty values
                if (is_array($pathValue) && !Utils::isObject($pathValue)) {
                    $pathValue = array_filter($pathValue);
                }
                return empty($pathValue) && $pathValue !== 0;
            case 'regex':
                return preg_match($value, $pathValue) === 1;
            case 'in':
                return in_array($pathValue, (array)$value);
            case 'not in':
                return !in_array($pathValue, (array)$value);
            case 'in list all':
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $pattern = '/' . Utils::custom_preg_escape(Utils::full_unescape($v)) . '/i';
                        if (!preg_match($pattern, $pathValue)) {
                            return false;
                        }
                    }
                    return true;
                }
                return preg_match('/' . Utils::custom_preg_escape(Utils::full_unescape($value)) . '/i', $pathValue);
            case 'not in list all':
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $pattern = '/' . Utils::custom_preg_escape(Utils::full_unescape($v)) . '/i';
                        if (preg_match($pattern, $pathValue)) {
                            return false;
                        }
                    }
                    return true;
                }
                return !preg_match('/' . Utils::custom_preg_escape(Utils::full_unescape($value)) . '/i', $pathValue);
            case 'in list any':
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $pattern = '/' . Utils::custom_preg_escape(Utils::full_unescape($v)) . '/i';
                        print "\npattern: $pattern\n";
                        print "\npath value: $pathValue\n";
                        if (preg_match($pattern, $pathValue)) {
                            return true;
                        }
                        if (preg_last_error() !== PREG_NO_ERROR) {
                            print "Preg Error: ".Utils::getPregError(preg_last_error());
                            throw new \Exception("Preg Error: ".Utils::getPregError(preg_last_error()));
                        }
                    }
                    return false;
                }
                return preg_match('/' . Utils::custom_preg_escape(Utils::full_unescape($value)) . '/i', $pathValue);
            case 'not in list any':
                if (is_array($value)) {
                    foreach ($value as $v) {
                        $pattern = '/' . Utils::custom_preg_escape(Utils::full_unescape($v)) . '/i';
                        if (!preg_match($pattern, $pathValue)) {
                            return true;
                        }
                    }
                    return false;
                }
                return !preg_match('/' . Utils::custom_preg_escape(Utils::full_unescape($value)) . '/i', $pathValue);
            case 'true':
                return $pathValue === true;
            case 'false':
                return $pathValue === false;
            case 'like':
                $pattern = str_replace('%', '.*', $value);
                return preg_match("/$pattern/", $pathValue) === 1;
            case 'similar_to':
                if ($tokenizeSimilarity) {
                    return $fuzz->tokenSortPartialRatio($pathValue, $value) >= $similarityThreshold;
                }
                return $fuzz->partialRatio($pathValue, $value) >= $similarityThreshold;
            default:
                throw new UnknownOperatorException("Unknown operator: $operator");
        }
    }
}
