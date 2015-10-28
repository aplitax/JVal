<?php

namespace JVal\Constraint;

use JVal\Constraint;
use JVal\Context;
use JVal\Exception\Constraint\InvalidTypeException;
use JVal\Exception\Constraint\LessThanZeroException;
use JVal\Types;
use JVal\Walker;
use stdClass;

abstract class AbstractCountConstraint implements Constraint
{
    public function normalize(stdClass $schema, Context $context, Walker $walker)
    {
        $keyword = $this->keywords()[0];
        $context->enterNode($schema->{$keyword}, $keyword);

        if (!is_int($schema->{$keyword})) {
            throw new InvalidTypeException($context, Types::TYPE_INTEGER);
        }

        if ($schema->{$keyword} < 0) {
            throw new LessThanZeroException($context);
        }

        $context->leaveNode();
    }
}
