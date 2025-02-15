<?php

namespace Psalm\Internal\Type;

use Exception;
use Psalm\CodeLocation;
use Psalm\Codebase;
use Psalm\Exception\TypeParseTreeException;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\VariableFetchAnalyzer;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Analyzer\TraitAnalyzer;
use Psalm\Internal\Type\Comparator\AtomicTypeComparator;
use Psalm\Internal\Type\Comparator\TypeComparisonResult;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\Issue\DocblockTypeContradiction;
use Psalm\Issue\InvalidDocblock;
use Psalm\Issue\TypeDoesNotContainNull;
use Psalm\Issue\TypeDoesNotContainType;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TClassConstant;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TEnumCase;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TIterable;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TList;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TPositiveInt;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;

use function array_intersect_key;
use function array_merge;
use function count;
use function explode;
use function get_class;
use function is_string;
use function strpos;
use function substr;

/**
 * @internal
 */
class AssertionReconciler extends Reconciler
{
    /**
     * Reconciles types
     *
     * think of this as a set of functions e.g. empty(T), notEmpty(T), null(T), notNull(T) etc. where
     *  - empty(Object) => null,
     *  - empty(bool) => false,
     *  - notEmpty(Object|null) => Object,
     *  - notEmpty(Object|false) => Object
     *
     * @param   string[]            $suppressed_issues
     * @param   array<string, array<string, Union>> $template_type_map
     * @param-out   0|1|2   $failed_reconciliation
     */
    public static function reconcile(
        string $assertion,
        ?Union $existing_var_type,
        ?string $key,
        StatementsAnalyzer $statements_analyzer,
        bool $inside_loop,
        array $template_type_map,
        ?CodeLocation $code_location = null,
        array $suppressed_issues = [],
        ?int &$failed_reconciliation = Reconciler::RECONCILIATION_OK,
        bool $negated = false
    ): Union {
        $codebase = $statements_analyzer->getCodebase();

        $is_strict_equality = false;
        $is_loose_equality = false;
        $is_equality = false;
        $is_negation = false;
        $failed_reconciliation = Reconciler::RECONCILIATION_OK;

        if ($assertion[0] === '!') {
            $assertion = substr($assertion, 1);
            $is_negation = true;
        }

        if ($assertion[0] === '=') {
            $assertion = substr($assertion, 1);
            $is_strict_equality = true;
            $is_equality = true;
        }

        if ($assertion[0] === '~') {
            $assertion = substr($assertion, 1);
            $is_loose_equality = true;
            $is_equality = true;
        }

        $original_assertion = $assertion;

        if ($assertion[0] === '@') {
            $assertion = 'falsy';
            $is_negation = true;
        }

        if ($existing_var_type === null
            && is_string($key)
            && VariableFetchAnalyzer::isSuperGlobal($key)
        ) {
            $existing_var_type = VariableFetchAnalyzer::getGlobalType($key);
        }

        if ($existing_var_type === null) {
            return self::getMissingType(
                $assertion,
                $is_negation,
                $inside_loop,
                $is_equality,
                $template_type_map
            );
        }

        $old_var_type_string = $existing_var_type->getId();

        if ($is_negation) {
            return NegatedAssertionReconciler::reconcile(
                $statements_analyzer,
                $assertion,
                $is_strict_equality,
                $is_loose_equality,
                $existing_var_type,
                $template_type_map,
                $old_var_type_string,
                $key,
                $negated,
                $code_location,
                $suppressed_issues,
                $failed_reconciliation,
                $inside_loop
            );
        }

        $simply_reconciled_type = SimpleAssertionReconciler::reconcile(
            $assertion,
            $codebase,
            $existing_var_type,
            $key,
            $negated,
            $code_location,
            $suppressed_issues,
            $failed_reconciliation,
            $is_equality,
            $is_strict_equality,
            $inside_loop
        );

        if ($simply_reconciled_type) {
            return $simply_reconciled_type;
        }

        if (strpos($assertion, 'isa-') === 0) {
            $should_return = false;

            $new_type = self::handleIsA(
                $codebase,
                $existing_var_type,
                $assertion,
                $template_type_map,
                $code_location,
                $key,
                $suppressed_issues,
                $should_return
            );

            if ($should_return) {
                return $new_type;
            }
        } elseif (strpos($assertion, 'getclass-') === 0) {
            $assertion = substr($assertion, 9);
            $new_type = Type::parseString($assertion, null, $template_type_map);
        } else {
            $bracket_pos = strpos($assertion, '(');

            if ($bracket_pos) {
                return self::handleLiteralEquality(
                    $statements_analyzer,
                    $assertion,
                    $bracket_pos,
                    $is_loose_equality,
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    $negated,
                    $code_location,
                    $suppressed_issues
                );
            }

            if ($assertion === 'loaded-class-string') {
                $assertion = 'class-string';
            }

            try {
                $new_type = Type::parseString($assertion, null, $template_type_map);
            } catch (TypeParseTreeException $e) {
                $new_type = Type::getMixed();
            }
        }

        if ($existing_var_type->hasMixed()) {
            if ($is_loose_equality
                && $new_type->hasScalarType()
            ) {
                return $existing_var_type;
            }

            return $new_type;
        }

        $refined_type = self::refine(
            $statements_analyzer,
            $assertion,
            $original_assertion,
            $new_type,
            $existing_var_type,
            $template_type_map,
            $key,
            $negated,
            $code_location,
            $is_equality,
            $is_loose_equality,
            $suppressed_issues,
            $failed_reconciliation
        );

        return TypeExpander::expandUnion(
            $codebase,
            $refined_type,
            null,
            null,
            null,
            true,
            false,
            false,
            true
        );
    }

    /**
     * @param array<string, array<string, Union>> $template_type_map
     */
    private static function getMissingType(
        string $assertion,
        bool $is_negation,
        bool $inside_loop,
        bool $is_equality,
        array $template_type_map
    ): Union {
        if (($assertion === 'isset' && !$is_negation)
            || ($assertion === 'empty' && $is_negation)
        ) {
            return Type::getMixed($inside_loop);
        }

        if ($assertion === 'array-key-exists'
            || $assertion === 'non-empty-countable'
            || strpos($assertion, 'has-at-least-') === 0
            || strpos($assertion, 'has-exactly-') === 0
        ) {
            return Type::getMixed();
        }

        if (!$is_negation && $assertion !== 'falsy' && $assertion !== 'empty') {
            if ($is_equality) {
                $bracket_pos = strpos($assertion, '(');

                if ($bracket_pos) {
                    $assertion = substr($assertion, 0, $bracket_pos);
                }
            }

            try {
                return Type::parseString($assertion, null, $template_type_map);
            } catch (Exception $e) {
                return Type::getMixed();
            }
        }

        return Type::getMixed();
    }

    /**
     * This method is called when SimpleAssertionReconciler was not enough. It receives the existing type, the assertion
     * and also a new type created from the assertion string.
     *
     * @param 0|1|2         $failed_reconciliation
     * @param   string[]    $suppressed_issues
     * @param   array<string, array<string, Union>> $template_type_map
     * @param-out   0|1|2   $failed_reconciliation
     */
    private static function refine(
        StatementsAnalyzer $statements_analyzer,
        string $assertion,
        string $original_assertion,
        Union $new_type,
        Union $existing_var_type,
        array $template_type_map,
        ?string $key,
        bool $negated,
        ?CodeLocation $code_location,
        bool $is_equality,
        bool $is_loose_equality,
        array $suppressed_issues,
        int &$failed_reconciliation
    ): Union {
        $codebase = $statements_analyzer->getCodebase();

        $old_var_type_string = $existing_var_type->getId();

        $new_type_has_interface = false;

        if ($new_type->hasObjectType()) {
            foreach ($new_type->getAtomicTypes() as $new_type_part) {
                if ($new_type_part instanceof TNamedObject &&
                    $codebase->interfaceExists($new_type_part->value)
                ) {
                    $new_type_has_interface = true;
                    break;
                }
            }
        }

        $old_type_has_interface = false;

        if ($existing_var_type->hasObjectType()) {
            foreach ($existing_var_type->getAtomicTypes() as $existing_type_part) {
                if ($existing_type_part instanceof TNamedObject &&
                    $codebase->interfaceExists($existing_type_part->value)
                ) {
                    $old_type_has_interface = true;
                    break;
                }
            }
        }

        try {
            if (strpos($assertion, '<') || strpos($assertion, '[') || strpos($assertion, '{')) {
                $new_type_union = Type::parseString($assertion);

                $new_type_part = $new_type_union->getSingleAtomic();
            } else {
                $new_type_part = Atomic::create($assertion, null, $template_type_map);
            }
        } catch (TypeParseTreeException $e) {
            $new_type_part = new TMixed();

            if ($code_location) {
                IssueBuffer::maybeAdd(
                    new InvalidDocblock(
                        $assertion . ' cannot be used in an assertion',
                        $code_location
                    ),
                    $suppressed_issues
                );
            }
        }

        if ($new_type_part instanceof TTemplateParam
            && $new_type_part->as->isSingle()
        ) {
            $new_as_atomic = $new_type_part->as->getSingleAtomic();

            $acceptable_atomic_types = [];

            foreach ($existing_var_type->getAtomicTypes() as $existing_var_type_part) {
                if ($existing_var_type_part instanceof TNamedObject
                    || $existing_var_type_part instanceof TTemplateParam
                ) {
                    $acceptable_atomic_types[] = clone $existing_var_type_part;
                } else {
                    if (AtomicTypeComparator::isContainedBy(
                        $codebase,
                        $existing_var_type_part,
                        $new_as_atomic
                    )) {
                        $acceptable_atomic_types[] = clone $existing_var_type_part;
                    }
                }
            }

            if ($acceptable_atomic_types) {
                $new_type_part->as = new Union($acceptable_atomic_types);

                return new Union([$new_type_part]);
            }
        }

        if ($new_type_part instanceof TKeyedArray) {
            $acceptable_atomic_types = [];

            foreach ($existing_var_type->getAtomicTypes() as $existing_var_type_part) {
                if ($existing_var_type_part instanceof TKeyedArray) {
                    if (!array_intersect_key(
                        $existing_var_type_part->properties,
                        $new_type_part->properties
                    )) {
                        $existing_var_type_part = clone $existing_var_type_part;
                        $existing_var_type_part->properties = array_merge(
                            $existing_var_type_part->properties,
                            $new_type_part->properties
                        );

                        $acceptable_atomic_types[] = $existing_var_type_part;
                    }
                }
            }

            if ($acceptable_atomic_types) {
                return new Union($acceptable_atomic_types);
            }
        }

        if ($new_type_part instanceof TNamedObject
            && ($new_type_has_interface || $old_type_has_interface)
            && !UnionTypeComparator::canExpressionTypesBeIdentical(
                $codebase,
                $new_type,
                $existing_var_type,
                false
            )
        ) {
            $acceptable_atomic_types = [];

            foreach ($existing_var_type->getAtomicTypes() as $existing_var_type_part) {
                if (AtomicTypeComparator::isContainedBy(
                    $codebase,
                    $existing_var_type_part,
                    $new_type_part
                )) {
                    $acceptable_atomic_types[] = clone $existing_var_type_part;
                    continue;
                }

                if ($existing_var_type_part instanceof TNamedObject
                    && ($codebase->classExists($existing_var_type_part->value)
                        || $codebase->interfaceExists($existing_var_type_part->value))
                ) {
                    $existing_var_type_part = clone $existing_var_type_part;
                    $existing_var_type_part->addIntersectionType($new_type_part);
                    $acceptable_atomic_types[] = $existing_var_type_part;
                }

                if ($existing_var_type_part instanceof TTemplateParam) {
                    $existing_var_type_part = clone $existing_var_type_part;
                    $existing_var_type_part->addIntersectionType($new_type_part);
                    $acceptable_atomic_types[] = $existing_var_type_part;
                }
            }

            if ($acceptable_atomic_types) {
                return new Union($acceptable_atomic_types);
            }
        } elseif (!$new_type->hasMixed()) {
            $any_scalar_type_match_found = false;

            if ($code_location
                && $key
                && !$is_equality
                && $new_type_part instanceof TNamedObject
                && !$new_type_has_interface
                && (!($statements_analyzer->getSource()->getSource() instanceof TraitAnalyzer)
                    || ($key !== '$this'
                        && strpos($original_assertion, 'isa-') !== 0))
                && UnionTypeComparator::isContainedBy(
                    $codebase,
                    $existing_var_type,
                    $new_type,
                    false,
                    false,
                    null,
                    false,
                    false
                )
            ) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $key,
                    $original_assertion,
                    true,
                    $negated,
                    $code_location,
                    $suppressed_issues
                );
            }

            $intersection_type = self::filterTypeWithAnother(
                $codebase,
                $existing_var_type,
                $new_type,
                $any_scalar_type_match_found
            );

            if ($code_location
                && !$intersection_type
                && (!$is_loose_equality || !$any_scalar_type_match_found)
            ) {
                if ($assertion === 'null') {
                    if ($existing_var_type->from_docblock) {
                        IssueBuffer::maybeAdd(
                            new DocblockTypeContradiction(
                                'Cannot resolve types for ' . $key . ' - docblock-defined type '
                                    . $existing_var_type . ' does not contain null',
                                $code_location,
                                $existing_var_type->getId() . ' null'
                            ),
                            $suppressed_issues
                        );
                    } else {
                        IssueBuffer::maybeAdd(
                            new TypeDoesNotContainNull(
                                'Cannot resolve types for ' . $key . ' - ' . $existing_var_type
                                    . ' does not contain null',
                                $code_location,
                                $existing_var_type->getId()
                            ),
                            $suppressed_issues
                        );
                    }
                } elseif (!($statements_analyzer->getSource()->getSource() instanceof TraitAnalyzer)
                    || ($key !== '$this'
                        && !($existing_var_type->hasLiteralClassString() && $new_type->hasLiteralClassString()))
                ) {
                    if ($existing_var_type->from_docblock) {
                        IssueBuffer::maybeAdd(
                            new DocblockTypeContradiction(
                                'Cannot resolve types for ' . $key . ' - docblock-defined type '
                                    . $existing_var_type->getId() . ' does not contain ' . $new_type->getId(),
                                $code_location,
                                $existing_var_type->getId() . ' ' . $new_type->getId()
                            ),
                            $suppressed_issues
                        );
                    } else {
                        IssueBuffer::maybeAdd(
                            new TypeDoesNotContainType(
                                'Cannot resolve types for ' . $key . ' - ' . $existing_var_type->getId()
                                    . ' does not contain ' . $new_type->getId(),
                                $code_location,
                                $existing_var_type->getId() . ' ' . $new_type->getId()
                            ),
                            $suppressed_issues
                        );
                    }
                }

                $failed_reconciliation = Reconciler::RECONCILIATION_EMPTY;
            }

            if ($intersection_type) {
                $new_type = $intersection_type;
            }
        }

        return $new_type;
    }

    /**
     * This method receives two types. The goal is to use datas in the new type to reduce the existing_type to a more
     * precise version. For example: new is `array<int>` old is `list<mixed>` so the result is `list<int>`
     *
     * @param array<string, array<string, Union>> $template_type_map
     */
    private static function filterTypeWithAnother(
        Codebase $codebase,
        Union $existing_type,
        Union $new_type,
        bool &$any_scalar_type_match_found = false
    ): ?Union {
        $matching_atomic_types = [];

        $new_type = clone $new_type;

        foreach ($new_type->getAtomicTypes() as $new_type_part) {
            foreach ($existing_type->getAtomicTypes() as $existing_type_part) {
                $matching_atomic_type = self::filterAtomicWithAnother(
                    $existing_type_part,
                    $new_type_part,
                    $codebase,
                    $any_scalar_type_match_found
                );

                if ($matching_atomic_type) {
                    $matching_atomic_types[] = $matching_atomic_type;
                }
            }
        }

        if ($matching_atomic_types) {
            $existing_type->bustCache();
            return new Union($matching_atomic_types);
        }

        return null;
    }

    private static function filterAtomicWithAnother(
        Atomic $type_1_atomic,
        Atomic $type_2_atomic,
        Codebase $codebase,
        bool &$any_scalar_type_match_found
    ): ?Atomic {
        if ($type_1_atomic instanceof TFloat
            && $type_2_atomic instanceof TInt
        ) {
            $any_scalar_type_match_found = true;
            return $type_2_atomic;
        }

        if ($type_1_atomic instanceof TNamedObject) {
            $type_1_atomic->was_static = false;
        }

        $atomic_comparison_results = new TypeComparisonResult();

        $atomic_contained_by = AtomicTypeComparator::isContainedBy(
            $codebase,
            $type_2_atomic,
            $type_1_atomic,
            !($type_1_atomic instanceof TNamedObject && $type_2_atomic instanceof TNamedObject),
            false,
            $atomic_comparison_results
        );

        if ($atomic_contained_by) {
            return self::refineContainedAtomicWithAnother(
                $type_1_atomic,
                $type_2_atomic,
                $codebase,
                $atomic_comparison_results->type_coerced ?? false
            );
        }

        $atomic_comparison_results = new TypeComparisonResult();

        $atomic_contained_by = AtomicTypeComparator::isContainedBy(
            $codebase,
            $type_1_atomic,
            $type_2_atomic,
            !($type_1_atomic instanceof TNamedObject && $type_2_atomic instanceof TNamedObject),
            false,
            $atomic_comparison_results
        );

        if ($atomic_contained_by) {
            return self::refineContainedAtomicWithAnother(
                $type_2_atomic,
                $type_1_atomic,
                $codebase,
                $atomic_comparison_results->type_coerced ?? false
            );
        }

        $matching_atomic_type = null;

        if ($type_1_atomic instanceof TNamedObject
            && $type_2_atomic instanceof TNamedObject
            && ($codebase->interfaceExists($type_1_atomic->value)
                || $codebase->interfaceExists($type_2_atomic->value))
        ) {
            $matching_atomic_type = clone $type_2_atomic;
            $matching_atomic_type->extra_types[$type_1_atomic->getKey()] = $type_1_atomic;

            return $matching_atomic_type;
        }

        if ($type_2_atomic instanceof TKeyedArray
            && $type_1_atomic instanceof TList
        ) {
            $type_2_key = $type_2_atomic->getGenericKeyType();
            $type_2_value = $type_2_atomic->getGenericValueType();

            if (!$type_2_key->hasString()) {
                $type_2_value = self::filterTypeWithAnother(
                    $codebase,
                    $type_1_atomic->type_param,
                    $type_2_value,
                    $any_scalar_type_match_found
                );

                if ($type_2_value === null) {
                    return null;
                }

                $hybrid_type_part = new TKeyedArray($type_2_atomic->properties);
                $hybrid_type_part->previous_key_type = Type::getInt();
                $hybrid_type_part->previous_value_type = $type_2_value;
                $hybrid_type_part->is_list = true;

                return $hybrid_type_part;
            }
        } elseif ($type_1_atomic instanceof TKeyedArray
            && $type_2_atomic instanceof TList
        ) {
            $type_1_key = $type_1_atomic->getGenericKeyType();
            $type_1_value = $type_1_atomic->getGenericValueType();

            if (!$type_1_key->hasString()) {
                $type_1_value = self::filterTypeWithAnother(
                    $codebase,
                    $type_2_atomic->type_param,
                    $type_1_value,
                    $any_scalar_type_match_found
                );

                if ($type_1_value === null) {
                    return null;
                }

                $hybrid_type_part = new TKeyedArray($type_1_atomic->properties);
                $hybrid_type_part->previous_key_type = Type::getInt();
                $hybrid_type_part->previous_value_type = $type_1_value;
                $hybrid_type_part->is_list = true;

                return $hybrid_type_part;
            }
        }

        if ($type_2_atomic instanceof TTemplateParam
            && $type_1_atomic instanceof TTemplateParam
            && $type_2_atomic->param_name !== $type_1_atomic->param_name
            && $type_2_atomic->as->hasObject()
            && $type_1_atomic->as->hasObject()
        ) {
            $matching_atomic_type = clone $type_2_atomic;

            $matching_atomic_type->extra_types[$type_1_atomic->getKey()] = $type_1_atomic;

            return $matching_atomic_type;
        }

        //we filter both types of standard iterables
        if (($type_2_atomic instanceof TGenericObject
                || $type_2_atomic instanceof TArray
                || $type_2_atomic instanceof TIterable)
            && ($type_1_atomic instanceof TGenericObject
                || $type_1_atomic instanceof TArray
                || $type_1_atomic instanceof TIterable)
            && count($type_2_atomic->type_params) === count($type_1_atomic->type_params)
        ) {
            foreach ($type_2_atomic->type_params as $i => $type_2_param) {
                $type_1_param = $type_1_atomic->type_params[$i];

                $type_2_param_id = $type_2_param->getId();

                $type_2_param = self::filterTypeWithAnother(
                    $codebase,
                    $type_1_param,
                    $type_2_param,
                    $any_scalar_type_match_found
                );

                if ($type_2_param === null) {
                    return null;
                }

                if ($type_1_atomic->type_params[$i]->getId() !== $type_2_param_id) {
                    /** @psalm-suppress PropertyTypeCoercion */
                    $type_1_atomic->type_params[$i] = $type_2_param;
                }
            }

            $matching_atomic_type = $type_1_atomic;
            $atomic_comparison_results->type_coerced = true;
        }

        //we filter the second part of a list with the second part of standard iterables
        if (($type_2_atomic instanceof TArray
                || $type_2_atomic instanceof TIterable)
            && $type_1_atomic instanceof TList
        ) {
            $type_2_param = $type_2_atomic->type_params[1];
            $type_1_param = $type_1_atomic->type_param;

            $type_2_param = self::filterTypeWithAnother(
                $codebase,
                $type_1_param,
                $type_2_param,
                $any_scalar_type_match_found
            );

            if ($type_2_param === null) {
                return null;
            }

            if ($type_1_atomic->type_param->getId() !== $type_2_param->getId()) {
                $type_1_atomic->type_param = $type_2_param;
            }

            $matching_atomic_type = $type_1_atomic;
            $atomic_comparison_results->type_coerced = true;
        }

        //we filter each property of a Keyed Array with the second part of standard iterables
        if (($type_2_atomic instanceof TArray
                || $type_2_atomic instanceof TIterable)
            && $type_1_atomic instanceof TKeyedArray
        ) {
            $type_2_param = $type_2_atomic->type_params[1];
            foreach ($type_1_atomic->properties as $property_key => $type_1_param) {
                $type_2_param = self::filterTypeWithAnother(
                    $codebase,
                    $type_1_param,
                    $type_2_param,
                    $any_scalar_type_match_found
                );

                if ($type_2_param === null) {
                    return null;
                }

                if ($type_1_atomic->properties[$property_key]->getId() !== $type_2_param->getId()) {
                    $type_1_atomic->properties[$property_key] = $type_2_param;
                }
            }

            $matching_atomic_type = $type_1_atomic;
            $atomic_comparison_results->type_coerced = true;
        }

        //These partial match wouldn't have been handled by AtomicTypeComparator
        $new_range = null;
        if ($type_2_atomic instanceof TIntRange && $type_1_atomic instanceof TPositiveInt) {
            $new_range = TIntRange::intersectIntRanges(
                TIntRange::convertToIntRange($type_1_atomic),
                $type_2_atomic
            );
        } elseif ($type_1_atomic instanceof TIntRange
            && $type_2_atomic instanceof TPositiveInt
        ) {
            $new_range = TIntRange::intersectIntRanges(
                $type_1_atomic,
                TIntRange::convertToIntRange($type_2_atomic)
            );
        } elseif ($type_2_atomic instanceof TIntRange
            && $type_1_atomic instanceof TIntRange
        ) {
            $new_range = TIntRange::intersectIntRanges(
                $type_1_atomic,
                $type_2_atomic
            );
        }

        if ($new_range !== null) {
            $matching_atomic_type = $new_range;
        }

        if (!$atomic_comparison_results->type_coerced && $atomic_comparison_results->scalar_type_match_found) {
            $any_scalar_type_match_found = true;
        }

        return $matching_atomic_type;
    }

    private static function refineContainedAtomicWithAnother(
        Atomic $type_1_atomic,
        Atomic $type_2_atomic,
        Codebase $codebase,
        bool $type_coerced
    ): ?Atomic {
        if ($type_coerced
            && get_class($type_2_atomic) === TNamedObject::class
            && $type_1_atomic instanceof TGenericObject
        ) {
            // this is a hack - it's not actually rigorous, as the params may be different
            return new TGenericObject(
                $type_2_atomic->value,
                $type_1_atomic->type_params
            );
        } elseif ($type_2_atomic instanceof TNamedObject
            && $type_1_atomic instanceof TTemplateParam
            && $type_1_atomic->as->hasObjectType()
        ) {
            $type_1_atomic = clone $type_1_atomic;
            $type_1_as = self::filterTypeWithAnother(
                $codebase,
                $type_1_atomic->as,
                new Union([$type_2_atomic])
            );

            if ($type_1_as === null) {
                return null;
            }

            $type_1_atomic->as = $type_1_as;

            return $type_1_atomic;
        } else {
            return clone $type_2_atomic;
        }
    }

    /**
     * @param  string[]          $suppressed_issues
     */
    private static function handleLiteralEquality(
        StatementsAnalyzer $statements_analyzer,
        string             $assertion,
        int                $bracket_pos,
        bool               $is_loose_equality,
        Union              $existing_var_type,
        string             $old_var_type_string,
        ?string            $var_id,
        bool               $negated,
        ?CodeLocation      $code_location,
        array              $suppressed_issues
    ): Union {
        $value = substr($assertion, $bracket_pos + 1, -1);

        $scalar_type = substr($assertion, 0, $bracket_pos);

        $existing_var_atomic_types = $existing_var_type->getAtomicTypes();

        if ($scalar_type === 'int') {
            return self::handleLiteralEqualityWithInt(
                $statements_analyzer,
                $assertion,
                $bracket_pos,
                $is_loose_equality,
                $existing_var_type,
                $old_var_type_string,
                $var_id,
                $negated,
                $code_location,
                $suppressed_issues
            );
        } elseif ($scalar_type === 'string'
            || $scalar_type === 'class-string'
            || $scalar_type === 'interface-string'
            || $scalar_type === 'callable-string'
            || $scalar_type === 'trait-string'
        ) {
            if ($existing_var_type->hasMixed()
                || $existing_var_type->hasScalar()
                || $existing_var_type->hasArrayKey()
            ) {
                if ($is_loose_equality) {
                    return $existing_var_type;
                }

                if ($scalar_type === 'class-string'
                    || $scalar_type === 'interface-string'
                    || $scalar_type === 'trait-string'
                ) {
                    return new Union([new TLiteralClassString($value)]);
                }

                return new Union([new TLiteralString($value)]);
            }

            $has_string = false;

            foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
                if ($existing_var_atomic_type instanceof TString) {
                    $has_string = true;
                } elseif ($existing_var_atomic_type instanceof TTemplateParam) {
                    if ($existing_var_atomic_type->as->hasMixed()
                        || $existing_var_atomic_type->as->hasString()
                        || $existing_var_atomic_type->as->hasScalar()
                        || $existing_var_atomic_type->as->hasArrayKey()
                    ) {
                        if ($is_loose_equality) {
                            return $existing_var_type;
                        }

                        $existing_var_atomic_type = clone $existing_var_atomic_type;

                        $existing_var_atomic_type->as = self::handleLiteralEquality(
                            $statements_analyzer,
                            $assertion,
                            $bracket_pos,
                            false,
                            $existing_var_atomic_type->as,
                            $old_var_type_string,
                            $var_id,
                            $negated,
                            $code_location,
                            $suppressed_issues
                        );

                        return new Union([$existing_var_atomic_type]);
                    }

                    if ($existing_var_atomic_type->as->hasString()) {
                        $has_string = true;
                    }
                }
            }

            if ($has_string) {
                $existing_string_types = $existing_var_type->getLiteralStrings();

                if ($existing_string_types) {
                    $can_be_equal = false;
                    $did_remove_type = false;

                    foreach ($existing_var_atomic_types as $atomic_key => $_) {
                        if ($atomic_key !== $assertion) {
                            $existing_var_type->removeType($atomic_key);
                            $did_remove_type = true;
                        } else {
                            $can_be_equal = true;
                        }
                    }

                    if ($var_id
                        && $code_location
                        && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existing_var_type,
                            $old_var_type_string,
                            $var_id,
                            $assertion,
                            $can_be_equal,
                            $negated,
                            $code_location,
                            $suppressed_issues
                        );
                    }
                } else {
                    if ($scalar_type === 'class-string'
                        || $scalar_type === 'interface-string'
                        || $scalar_type === 'trait-string'
                    ) {
                        $existing_var_type = new Union([new TLiteralClassString($value)]);
                    } else {
                        $existing_var_type = new Union([new TLiteralString($value)]);
                    }
                }
            } elseif ($var_id && $code_location && !$is_loose_equality) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $var_id,
                    $assertion,
                    false,
                    $negated,
                    $code_location,
                    $suppressed_issues
                );
            }
        } elseif ($scalar_type === 'float') {
            $value = (float) $value;

            if ($existing_var_type->hasMixed() || $existing_var_type->hasScalar() || $existing_var_type->hasNumeric()) {
                if ($is_loose_equality) {
                    return $existing_var_type;
                }

                return new Union([new TLiteralFloat($value)]);
            }

            if ($existing_var_type->hasFloat()) {
                $existing_float_types = $existing_var_type->getLiteralFloats();

                if ($existing_float_types) {
                    $can_be_equal = false;
                    $did_remove_type = false;

                    foreach ($existing_var_atomic_types as $atomic_key => $_) {
                        if ($atomic_key !== $assertion) {
                            $existing_var_type->removeType($atomic_key);
                            $did_remove_type = true;
                        } else {
                            $can_be_equal = true;
                        }
                    }

                    if ($var_id
                        && $code_location
                        && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existing_var_type,
                            $old_var_type_string,
                            $var_id,
                            $assertion,
                            $can_be_equal,
                            $negated,
                            $code_location,
                            $suppressed_issues
                        );
                    }
                } else {
                    $existing_var_type = new Union([new TLiteralFloat($value)]);
                }
            } elseif ($var_id && $code_location && !$is_loose_equality) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $var_id,
                    $assertion,
                    false,
                    $negated,
                    $code_location,
                    $suppressed_issues
                );
            } elseif ($is_loose_equality && $existing_var_type->hasInt()) {
                // convert ints to floats
                $existing_float_types = $existing_var_type->getLiteralInts();

                if ($existing_float_types) {
                    $can_be_equal = false;
                    $did_remove_type = false;

                    foreach ($existing_var_atomic_types as $atomic_key => $_) {
                        if (strpos($atomic_key, 'int(') === 0) {
                            $atomic_key = 'float(' . substr($atomic_key, 4);
                        }
                        if ($atomic_key !== $assertion) {
                            $existing_var_type->removeType($atomic_key);
                            $did_remove_type = true;
                        } else {
                            $can_be_equal = true;
                        }
                    }

                    if ($var_id
                        && $code_location
                        && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
                    ) {
                        self::triggerIssueForImpossible(
                            $existing_var_type,
                            $old_var_type_string,
                            $var_id,
                            $assertion,
                            $can_be_equal,
                            $negated,
                            $code_location,
                            $suppressed_issues
                        );
                    }
                }
            }
        } elseif ($scalar_type === 'enum') {
            [$fq_enum_name, $case_name] = explode('::', $value);

            if ($existing_var_type->hasMixed()) {
                if ($is_loose_equality) {
                    return $existing_var_type;
                }

                return new Union([new TEnumCase($fq_enum_name, $case_name)]);
            }

            $can_be_equal = false;
            $did_remove_type = false;

            foreach ($existing_var_atomic_types as $atomic_key => $atomic_type) {
                if (get_class($atomic_type) === TNamedObject::class
                    && $atomic_type->value === $fq_enum_name
                ) {
                    $can_be_equal = true;
                    $did_remove_type = true;
                    $existing_var_type->removeType($atomic_key);
                    $existing_var_type->addType(new TEnumCase($fq_enum_name, $case_name));
                } elseif ($atomic_key !== $assertion) {
                    $existing_var_type->removeType($atomic_key);
                    $did_remove_type = true;
                } else {
                    $can_be_equal = true;
                }
            }

            if ($var_id
                && $code_location
                && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
            ) {
                self::triggerIssueForImpossible(
                    $existing_var_type,
                    $old_var_type_string,
                    $var_id,
                    $assertion,
                    $can_be_equal,
                    $negated,
                    $code_location,
                    $suppressed_issues
                );
            }
        }

        return $existing_var_type;
    }

    /**
     * @param  string[]          $suppressed_issues
     */
    private static function handleLiteralEqualityWithInt(
        StatementsAnalyzer $statements_analyzer,
        string             $assertion,
        int                $bracket_pos,
        bool               $is_loose_equality,
        Union              $existing_var_type,
        string             $old_var_type_string,
        ?string            $var_id,
        bool               $negated,
        ?CodeLocation      $code_location,
        array              $suppressed_issues
    ): Union {
        $value = (int) substr($assertion, $bracket_pos + 1, -1);

        $compatible_int_type = self::getCompatibleIntType($existing_var_type, $value, $is_loose_equality);
        if ($compatible_int_type !== null) {
            return $compatible_int_type;
        }

        $has_int = false;
        $existing_var_atomic_types = $existing_var_type->getAtomicTypes();
        foreach ($existing_var_atomic_types as $existing_var_atomic_type) {
            if ($existing_var_atomic_type instanceof TInt) {
                $has_int = true;
            } elseif ($existing_var_atomic_type instanceof TTemplateParam) {
                $compatible_int_type = self::getCompatibleIntType($existing_var_type, $value, $is_loose_equality);
                if ($compatible_int_type !== null) {
                    return $compatible_int_type;
                }

                if ($existing_var_atomic_type->as->hasInt()) {
                    $has_int = true;
                }
            } elseif ($existing_var_atomic_type instanceof TClassConstant) {
                $expanded = TypeExpander::expandAtomic(
                    $statements_analyzer->getCodebase(),
                    $existing_var_atomic_type,
                    $existing_var_atomic_type->fq_classlike_name,
                    $existing_var_atomic_type->fq_classlike_name,
                    null,
                    true,
                    true
                );

                if ($expanded instanceof Atomic) {
                    $compatible_int_type = self::getCompatibleIntType($existing_var_type, $value, $is_loose_equality);
                    if ($compatible_int_type !== null) {
                        return $compatible_int_type;
                    }

                    if ($expanded instanceof TInt) {
                        $has_int = true;
                    }
                } else {
                    foreach ($expanded as $expanded_type) {
                        $compatible_int_type = self::getCompatibleIntType(
                            $existing_var_type,
                            $value,
                            $is_loose_equality
                        );

                        if ($compatible_int_type !== null) {
                            return $compatible_int_type;
                        }

                        if ($expanded_type instanceof TInt) {
                            $has_int = true;
                        }
                    }
                }
            }
        }

        if ($has_int) {
            $existing_int_types = $existing_var_type->getLiteralInts();

            if ($existing_int_types) {
                $can_be_equal = false;
                $did_remove_type = false;

                foreach ($existing_var_atomic_types as $atomic_key => $atomic_type) {
                    if ($atomic_key !== $assertion
                        && !($atomic_type instanceof TPositiveInt && $value > 0)
                        && !($atomic_type instanceof TIntRange && $atomic_type->contains($value))
                    ) {
                        $existing_var_type->removeType($atomic_key);
                        $did_remove_type = true;
                    } else {
                        $can_be_equal = true;
                    }
                }

                if ($var_id
                    && $code_location
                    && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
                ) {
                    self::triggerIssueForImpossible(
                        $existing_var_type,
                        $old_var_type_string,
                        $var_id,
                        $assertion,
                        $can_be_equal,
                        $negated,
                        $code_location,
                        $suppressed_issues
                    );
                }
            } else {
                $existing_var_type = new Union([new TLiteralInt($value)]);
            }
        } elseif ($var_id && $code_location && !$is_loose_equality) {
            self::triggerIssueForImpossible(
                $existing_var_type,
                $old_var_type_string,
                $var_id,
                $assertion,
                false,
                $negated,
                $code_location,
                $suppressed_issues
            );
        } elseif ($is_loose_equality && $existing_var_type->hasFloat()) {
            // convert floats to ints
            $existing_float_types = $existing_var_type->getLiteralFloats();

            if ($existing_float_types) {
                $can_be_equal = false;
                $did_remove_type = false;

                foreach ($existing_var_atomic_types as $atomic_key => $_) {
                    if (strpos($atomic_key, 'float(') === 0) {
                        $atomic_key = 'int(' . substr($atomic_key, 6);
                    }
                    if ($atomic_key !== $assertion) {
                        $existing_var_type->removeType($atomic_key);
                        $did_remove_type = true;
                    } else {
                        $can_be_equal = true;
                    }
                }

                if ($var_id
                    && $code_location
                    && (!$can_be_equal || (!$did_remove_type && count($existing_var_atomic_types) === 1))
                ) {
                    self::triggerIssueForImpossible(
                        $existing_var_type,
                        $old_var_type_string,
                        $var_id,
                        $assertion,
                        $can_be_equal,
                        $negated,
                        $code_location,
                        $suppressed_issues
                    );
                }
            }
        }

        return $existing_var_type;
    }

    private static function getCompatibleIntType(
        Union $existing_var_type,
        int $value,
        bool $is_loose_equality
    ): ?Union {
        if ($existing_var_type->hasMixed()
            || $existing_var_type->hasScalar()
            || $existing_var_type->hasNumeric()
            || $existing_var_type->hasArrayKey()
        ) {
            if ($is_loose_equality) {
                return $existing_var_type;
            }

            return new Union([new TLiteralInt($value)]);
        }

        return null;
    }

    /**
     * @param array<string, array<string, Union>> $template_type_map
     * @param array<string>           $suppressed_issues
     */
    private static function handleIsA(
        Codebase $codebase,
        Union $existing_var_type,
        string &$assertion,
        array $template_type_map,
        ?CodeLocation $code_location,
        ?string $key,
        array $suppressed_issues,
        bool &$should_return
    ): Union {
        $assertion = substr($assertion, 4);

        $allow_string_comparison = false;

        if (strpos($assertion, 'string-') === 0) {
            $assertion = substr($assertion, 7);
            $allow_string_comparison = true;
        }

        if ($existing_var_type->hasMixed()) {
            $type = new Union([
                new TNamedObject($assertion),
            ]);

            if ($allow_string_comparison) {
                $type->addType(
                    new TClassString(
                        $assertion,
                        new TNamedObject($assertion)
                    )
                );
            }

            $should_return = true;
            return $type;
        }

        $existing_has_object = $existing_var_type->hasObjectType();
        $existing_has_string = $existing_var_type->hasString();

        if ($existing_has_object && !$existing_has_string) {
            return Type::parseString($assertion, null, $template_type_map);
        }

        if ($existing_has_string && !$existing_has_object) {
            if (!$allow_string_comparison && $code_location) {
                IssueBuffer::maybeAdd(
                    new TypeDoesNotContainType(
                        'Cannot allow string comparison to object for ' . $key,
                        $code_location,
                        null
                    ),
                    $suppressed_issues
                );

                return Type::getMixed();
            } else {
                $new_type_has_interface_string = $codebase->interfaceExists($assertion);

                $old_type_has_interface_string = false;

                foreach ($existing_var_type->getAtomicTypes() as $existing_type_part) {
                    if ($existing_type_part instanceof TClassString
                        && $existing_type_part->as_type
                        && $codebase->interfaceExists($existing_type_part->as_type->value)
                    ) {
                        $old_type_has_interface_string = true;
                        break;
                    }
                }

                if (isset($template_type_map[$assertion])) {
                    $new_type = Type::parseString(
                        'class-string<' . $assertion . '>',
                        null,
                        $template_type_map
                    );
                } else {
                    $new_type = Type::getClassString($assertion);
                }

                if ((
                        $new_type_has_interface_string
                        && !UnionTypeComparator::isContainedBy(
                            $codebase,
                            $existing_var_type,
                            $new_type
                        )
                    )
                    || (
                        $old_type_has_interface_string
                        && !UnionTypeComparator::isContainedBy(
                            $codebase,
                            $new_type,
                            $existing_var_type
                        )
                    )
                ) {
                    $new_type_part = Atomic::create($assertion, null, $template_type_map);

                    $acceptable_atomic_types = [];

                    foreach ($existing_var_type->getAtomicTypes() as $existing_var_type_part) {
                        if (!$new_type_part instanceof TNamedObject
                            || !$existing_var_type_part instanceof TClassString
                        ) {
                            $acceptable_atomic_types = [];

                            break;
                        }

                        if (!$existing_var_type_part->as_type instanceof TNamedObject) {
                            $acceptable_atomic_types = [];

                            break;
                        }

                        $existing_var_type_part = $existing_var_type_part->as_type;

                        if (AtomicTypeComparator::isContainedBy(
                            $codebase,
                            $existing_var_type_part,
                            $new_type_part
                        )) {
                            $acceptable_atomic_types[] = clone $existing_var_type_part;
                            continue;
                        }

                        if ($codebase->classExists($existing_var_type_part->value)
                            || $codebase->interfaceExists($existing_var_type_part->value)
                        ) {
                            $existing_var_type_part = clone $existing_var_type_part;
                            $existing_var_type_part->addIntersectionType($new_type_part);
                            $acceptable_atomic_types[] = $existing_var_type_part;
                        }
                    }

                    if (count($acceptable_atomic_types) === 1) {
                        $should_return = true;

                        return new Union([
                            new TClassString('object', $acceptable_atomic_types[0]),
                        ]);
                    }
                }
            }

            return $new_type;
        } else {
            return Type::getMixed();
        }
    }
}
