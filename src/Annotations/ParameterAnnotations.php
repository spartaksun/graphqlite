<?php

declare(strict_types=1);

namespace TheCodingMachine\GraphQLite\Annotations;

use function array_filter;
use function array_pop;
use function count;

/**
 * A list of annotations that implement the ParameterAnnotation interface
 */
class ParameterAnnotations
{
    /** @var array<int, ParameterAnnotationInterface> */
    private $annotations;

    /**
     * @param array<int, ParameterAnnotationInterface> $annotations
     */
    public function __construct(array $annotations)
    {
        $this->annotations = $annotations;
    }

    /**
     * Return annotations of the $className type
     *
     * @return array<int, ParameterAnnotationInterface>
     */
    public function getAnnotationsByType(string $className): array
    {
        return array_filter($this->annotations, static function (ParameterAnnotationInterface $annotation) use ($className) {
            return $annotation instanceof $className;
        });
    }

    /**
     * Returns at most 1 annotation of the $className type.
     */
    public function getAnnotationByType(string $className): ?ParameterAnnotationInterface
    {
        $annotations = $this->getAnnotationsByType($className);
        $count = count($annotations);
        if ($count > 1) {
            throw TooManyAnnotationsException::forClass($className);
        }

        if ($count === 0) {
            return null;
        }

        return array_pop($annotations);
    }
}
