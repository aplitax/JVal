<?php

/*
 * This file is part of the JVal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JVal\Exception\Resolver;

use JVal\Exception\ResolverException;

class UnresolvedPointerIndexException extends ResolverException
{
    protected function buildMessage(array $parameters)
    {
        return vsprintf(
            'Cannot resolve index "%s" at position %s in pointer "%s"',
            $parameters
        );
    }
}
