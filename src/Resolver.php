<?php

namespace JsonSchema;

use JsonSchema\Exception\Resolver\EmptyStackException;
use JsonSchema\Exception\Resolver\InvalidPointerIndexException;
use JsonSchema\Exception\Resolver\InvalidPointerTargetException;
use JsonSchema\Exception\Resolver\InvalidRemoteSchemaException;
use JsonSchema\Exception\Resolver\InvalidSegmentTypeException;
use JsonSchema\Exception\Resolver\JsonDecodeErrorException;
use JsonSchema\Exception\Resolver\SelfReferencingPointerException;
use JsonSchema\Exception\Resolver\UnfetchableUriException;
use JsonSchema\Exception\Resolver\UnresolvedPointerIndexException;
use JsonSchema\Exception\Resolver\UnresolvedPointerPropertyException;
use Closure;
use stdClass;

class Resolver
{
    private $stack = [];
    private $schemas = [];
    private $preFetchHook;

    /**
     * Returns whether a base schema has been set.
     *
     * @return bool
     */
    public function hasBaseSchema()
    {
        return count($this->stack) > 0;
    }

    /**
     * Sets the current schema, on which resolutions will be based.
     *
     * @param stdClass  $schema
     * @param Uri       $uri
     */
    public function setBaseSchema(stdClass $schema, Uri $uri)
    {
        $this->registerSchema($schema, $uri);
        $this->stack = [[$uri, $schema]];
    }

    /**
     * Returns the current base schema.
     *
     * @return stdClass
     * @throws EmptyStackException
     */
    public function getBaseSchema()
    {
        if (count($this->stack) === 0) {
            throw new EmptyStackException();
        }

        return $this->stack[0][1];
    }

    /**
     * Returns the URI of the current schema.
     *
     * @return Uri
     * @throws EmptyStackException
     */
    public function getCurrentUri()
    {
        if (count($this->stack) === 0) {
            throw new EmptyStackException();
        }

        return end($this->stack)[0];
    }

    /**
     * Returns the current schema.
     *
     * @return stdClass
     * @throws EmptyStackException
     */
    public function getCurrentSchema()
    {
        if (count($this->stack) === 0) {
            throw new EmptyStackException();
        }

        return end($this->stack)[1];
    }

    /**
     * Sets an URI pre-fetch hook. The hook function will be called each time
     * a remote reference is about to be fetched. It is passed the original
     * pointer URI and must return a new URI string.
     *
     * @param Closure $preFetchHook
     */
    public function setPreFetchHook(Closure $preFetchHook)
    {
        $this->preFetchHook = $preFetchHook;
    }

    public function enter(Uri $uri, stdClass $schema = null)
    {
        $currentUri = $this->getCurrentUri();

        if (!$uri->isAbsolute()) {
            $uri->resolveAgainst($currentUri);
        }

        $this->stack[] = [$uri, $schema ?: $this->getCurrentSchema()];
    }

    public function leave()
    {
        if (count($this->stack) === 0) {
            throw new EmptyStackException();
        }

        array_pop($this->stack);
    }

    /**
     * Resolves a schema reference according to the JSON Reference
     * specification draft. Returns an array containing the resolved
     * URI and the resolved schema.
     *
     * @param stdClass $reference
     * @throws InvalidPointerTargetException
     * @throws SelfReferencingPointerException
     * @return array
     */
    public function resolve(stdClass $reference)
    {
        $baseUri = $this->getCurrentUri();
        $uri = new Uri($reference->{'$ref'});

        if (!$uri->isAbsolute()) {
            $uri->resolveAgainst($baseUri);
        }

        $identifier = $uri->getPrimaryResourceIdentifier();

        if (!isset($this->schemas[$identifier])) {
            $schema = $this->fetchSchemaAt($identifier);
            $this->registerSchema($schema, $uri);
        } else {
            $schema = $this->schemas[$identifier];
        }

        $resolved = $this->resolvePointer($schema, $uri);

        if ($resolved === $reference) {
            throw new SelfReferencingPointerException();
        }

        if (!is_object($resolved)) {
            throw new InvalidPointerTargetException([$uri->getRawUri()]);
        }

        return [$uri, $resolved];
    }

    /**
     * Caches a schema reference for future use.
     *
     * @param stdClass  $schema
     * @param Uri       $uri
     */
    private function registerSchema(stdClass $schema, Uri $uri)
    {
        if (!isset($this->schemas[$uri->getPrimaryResourceIdentifier()])) {
            $this->schemas[$uri->getPrimaryResourceIdentifier()] = $schema;
        }
    }

    /**
     * Fetches a remote schema and ensures it is valid.
     *
     * @param string $uri
     * @throws InvalidRemoteSchemaException
     * @throws JsonDecodeErrorException
     * @return stdClass
     */
    private function fetchSchemaAt($uri)
    {
        if ($hook = $this->preFetchHook) {
            $uri = $hook($uri);
        }

        set_error_handler(function ($severity, $error) use ($uri) {
            restore_error_handler();
            throw new UnfetchableUriException([$uri, $error, $severity]);
        });

        $content = file_get_contents($uri);
        restore_error_handler();

        $schema = json_decode($content);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonDecodeErrorException([$uri, json_last_error_msg()]);
        }

        if (!is_object($schema)) {
            throw new InvalidRemoteSchemaException([$uri]);
        }

        return $schema;
    }

    /**
     * Resolves a JSON pointer according to RFC 6901.
     *
     * @param stdClass  $schema
     * @param Uri       $pointerUri
     * @return mixed
     * @throws InvalidPointerIndexException
     * @throws InvalidSegmentTypeException
     * @throws UnresolvedPointerIndexException
     * @throws UnresolvedPointerPropertyException
     */
    private function resolvePointer(stdClass $schema, Uri $pointerUri)
    {
        $segments = $pointerUri->getPointerSegments();
        $pointer = $pointerUri->getRawPointer();
        $currentNode = $schema;

        for ($i = 0, $max = count($segments); $i < $max; ++$i) {
            if (is_object($currentNode)) {
                if (property_exists($currentNode, $segments[$i])) {
                    $currentNode = $currentNode->{$segments[$i]};
                    continue;
                }

                throw new UnresolvedPointerPropertyException([$segments[$i], $i, $pointer]);
            }

            if (is_array($currentNode)) {
                if (!preg_match('/^\d+$/', $segments[$i])) {
                    throw new InvalidPointerIndexException([$segments[$i], $i, $pointer]);
                }

                if (!isset($currentNode[$index = (int) $segments[$i]])) {
                    throw new UnresolvedPointerIndexException([$segments[$i], $i, $pointer]);
                }

                $currentNode = $currentNode[$index];
                continue;
            }

            throw new InvalidSegmentTypeException([$i, $pointer]);
        }

        return $currentNode;
    }
}
