<?php

declare(strict_types=1);

namespace TheCodingMachine\GraphQLite\Mappers\Parameters;

use GraphQL\Type\Definition\ResolveInfo;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\Type;
use ReflectionParameter;
use TheCodingMachine\GraphQLite\Annotations\ParameterAnnotations;
use TheCodingMachine\GraphQLite\Parameters\ParameterInterface;
use TheCodingMachine\GraphQLite\Parameters\ResolveInfoParameter;

class ResolveInfoParameterHandler implements ParameterMiddlewareInterface
{
    public function mapParameter(ReflectionParameter $parameter, DocBlock $docBlock, ?Type $paramTagType, ParameterAnnotations $parameterAnnotations, ParameterHandlerInterface $parameterMapper): ParameterInterface
    {
        $type = $parameter->getType();
        if ($type!== null && $type->getName() === ResolveInfo::class) {
            return new ResolveInfoParameter();
        }

        return $parameterMapper->mapParameter($parameter, $docBlock, $paramTagType, $parameterAnnotations);
    }
}
