<?php

declare(strict_types=1);

namespace TheCodingMachine\GraphQLite\Mappers;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\SourceFieldInterface;
use Throwable;

interface CannotMapTypeExceptionInterface extends Throwable
{
    public function addParamInfo(ReflectionParameter $parameter): void;

    public function addReturnInfo(ReflectionMethod $method): void;

    public function addSourceFieldInfo(ReflectionClass $class, SourceFieldInterface $sourceField): void;

    public function addExtendTypeInfo(ReflectionClass $class, ExtendType $extendType): void;
}
