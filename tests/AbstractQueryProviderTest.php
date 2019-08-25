<?php


namespace TheCodingMachine\GraphQLite;

use Doctrine\Common\Annotations\Annotation;
use Doctrine\Common\Annotations\AnnotationReader as DoctrineAnnotationReader;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\OutputType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\StringType;
use GraphQL\Type\Definition\Type;
use Mouf\Picotainer\Picotainer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Simple\ArrayCache;
use Symfony\Component\Lock\Factory as LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;
use TheCodingMachine\GraphQLite\Fixtures\Mocks\MockResolvableInputObjectType;
use TheCodingMachine\GraphQLite\Fixtures\TestObject;
use TheCodingMachine\GraphQLite\Fixtures\TestObject2;
use TheCodingMachine\GraphQLite\Fixtures\TestObjectWithRecursiveList;
use TheCodingMachine\GraphQLite\Fixtures\Types\TestFactory;
use TheCodingMachine\GraphQLite\Mappers\CannotMapTypeException;
use TheCodingMachine\GraphQLite\Mappers\CannotMapTypeExceptionInterface;
use TheCodingMachine\GraphQLite\Mappers\Parameters\CompositeParameterMapper;
use TheCodingMachine\GraphQLite\Mappers\Parameters\ResolveInfoParameterMapper;
use TheCodingMachine\GraphQLite\Mappers\RecursiveTypeMapper;
use TheCodingMachine\GraphQLite\Mappers\RecursiveTypeMapperInterface;
use TheCodingMachine\GraphQLite\Mappers\Root\BaseTypeMapper;
use TheCodingMachine\GraphQLite\Mappers\Root\CompositeRootTypeMapper;
use TheCodingMachine\GraphQLite\Mappers\Root\MyCLabsEnumTypeMapper;
use TheCodingMachine\GraphQLite\Mappers\TypeMapperInterface;
use TheCodingMachine\GraphQLite\Containers\EmptyContainer;
use TheCodingMachine\GraphQLite\Containers\BasicAutoWiringContainer;
use TheCodingMachine\GraphQLite\Middlewares\AuthorizationFieldMiddleware;
use TheCodingMachine\GraphQLite\Reflection\CachedDocBlockFactory;
use TheCodingMachine\GraphQLite\Security\VoidAuthenticationService;
use TheCodingMachine\GraphQLite\Security\VoidAuthorizationService;
use TheCodingMachine\GraphQLite\Types\ArgumentResolver;
use TheCodingMachine\GraphQLite\Types\MutableInterface;
use TheCodingMachine\GraphQLite\Types\MutableObjectType;
use TheCodingMachine\GraphQLite\Types\ResolvableMutableInputInterface;
use TheCodingMachine\GraphQLite\Types\ResolvableMutableInputObjectType;
use TheCodingMachine\GraphQLite\Types\TypeResolver;

abstract class AbstractQueryProviderTest extends TestCase
{
    private $testObjectType;
    private $testObjectType2;
    private $inputTestObjectType;
    private $typeMapper;
    private $argumentResolver;
    private $registry;
    private $typeGenerator;
    private $inputTypeGenerator;
    private $inputTypeUtils;
    private $fieldsBuilder;
    private $annotationReader;
    private $typeResolver;
    private $typeRegistry;
    private $lockFactory;

    protected function getTestObjectType(): MutableObjectType
    {
        if ($this->testObjectType === null) {
            $this->testObjectType = new MutableObjectType([
                'name'    => 'TestObject',
                'fields'  => [
                    'test'   => Type::string(),
                ],
            ]);
        }
        return $this->testObjectType;
    }

    protected function getTestObjectType2(): MutableObjectType
    {
        if ($this->testObjectType2 === null) {
            $this->testObjectType2 = new MutableObjectType([
                'name'    => 'TestObject2',
                'fields'  => [
                    'test'   => Type::string(),
                ],
            ]);
        }
        return $this->testObjectType2;
    }

    protected function getInputTestObjectType(): MockResolvableInputObjectType
    {
        if ($this->inputTestObjectType === null) {
            $this->inputTestObjectType = new MockResolvableInputObjectType([
                'name'    => 'TestObjectInput',
                'fields'  => [
                    'test'   => Type::string(),
                ],
            ], function($source, $args) {
                return new TestObject($args['test']);
            });
        }
        return $this->inputTestObjectType;
    }

    protected function getTypeMapper()
    {
        if ($this->typeMapper === null) {
            $this->typeMapper = new RecursiveTypeMapper(new class($this->getTestObjectType(), $this->getTestObjectType2(), $this->getInputTestObjectType()/*, $this->getInputTestObjectType2()*/) implements TypeMapperInterface {
                /**
                 * @var ObjectType
                 */
                private $testObjectType;
                /**
                 * @var ObjectType
                 */
                private $testObjectType2;
                /**
                 * @var InputObjectType
                 */
                private $inputTestObjectType;
                /**
                 * @var InputObjectType
                 */
//                private $inputTestObjectType2;

                public function __construct(ObjectType $testObjectType, ObjectType $testObjectType2, InputObjectType $inputTestObjectType/*, InputObjectType $inputTestObjectType2*/)
                {
                    $this->testObjectType = $testObjectType;
                    $this->testObjectType2 = $testObjectType2;
                    $this->inputTestObjectType = $inputTestObjectType;
                    //$this->inputTestObjectType2 = $inputTestObjectType2;
                }

                public function mapClassToType(string $className, ?OutputType $subType): MutableInterface
                {
                    if ($className === TestObject::class) {
                        return $this->testObjectType;
                    } elseif ($className === TestObject2::class) {
                        return $this->testObjectType2;
                    } else {
                        throw CannotMapTypeException::createForType($className);
                    }
                }

                public function mapClassToInputType(string $className): ResolvableMutableInputInterface
                {
                    if ($className === TestObject::class) {
                        return $this->inputTestObjectType;
                    } /*elseif ($className === TestObjectWithRecursiveList::class) {
                        return $this->inputTestObjectType2;
                    } */else {
                        throw CannotMapTypeException::createForInputType($className);
                    }
                }

                public function canMapClassToType(string $className): bool
                {
                    return $className === TestObject::class || $className === TestObject2::class;
                }

                public function canMapClassToInputType(string $className): bool
                {
                    return $className === TestObject::class || $className === TestObject2::class;
                }

                public function getSupportedClasses(): array
                {
                    return [TestObject::class, TestObject2::class];
                }

                public function mapNameToType(string $typeName): Type
                {
                    switch ($typeName) {
                        case 'TestObject':
                            return $this->testObjectType;
                        case 'TestObject2':
                            return $this->testObjectType2;
                        case 'TestObjectInput':
                            return $this->inputTestObjectType;
                        default:
                            throw CannotMapTypeException::createForName($typeName);
                    }
                }

                public function canMapNameToType(string $typeName): bool
                {
                    return $typeName === 'TestObject' || $typeName === 'TestObject2' || $typeName === 'TestObjectInput';
                }

                public function canExtendTypeForClass(string $className, MutableInterface $type): bool
                {
                    return false;
                }

                public function extendTypeForClass(string $className, MutableInterface $type): void
                {
                    throw CannotMapTypeException::createForExtendType($className, $type);
                }

                public function canExtendTypeForName(string $typeName, MutableInterface $type): bool
                {
                    return false;
                }

                public function extendTypeForName(string $typeName, MutableInterface $type): void
                {
                    throw CannotMapTypeException::createForExtendName($typeName, $type);
                }

                public function canDecorateInputTypeForName(string $typeName, ResolvableMutableInputInterface $type): bool
                {
                    return false;
                }

                public function decorateInputTypeForName(string $typeName, ResolvableMutableInputInterface $type): void
                {
                    throw CannotMapTypeException::createForDecorateName($typeName, $type);
                }

            }, new NamingStrategy(), new ArrayCache(), $this->getTypeRegistry());
        }
        return $this->typeMapper;
    }

    protected function getArgumentResolver(): ArgumentResolver
    {
        if ($this->argumentResolver === null) {
            $this->argumentResolver = new ArgumentResolver();
        }
        return $this->argumentResolver;
    }

    protected function getRegistry()
    {
        if ($this->registry === null) {
            $this->registry = $this->buildAutoWiringContainer(new Picotainer([
                /*'customOutput' => function() {
                    return new StringType();
                }*/
            ]));
        }
        return $this->registry;
    }

    protected function buildAutoWiringContainer(ContainerInterface $container): BasicAutoWiringContainer
    {
        return new BasicAutoWiringContainer($container);
    }

    protected function getAnnotationReader(): AnnotationReader
    {
        if ($this->annotationReader === null) {
            $this->annotationReader = new AnnotationReader(new DoctrineAnnotationReader());
        }
        return $this->annotationReader;
    }

    protected function buildFieldsBuilder(): FieldsBuilder
    {
        return new FieldsBuilder(
            $this->getAnnotationReader(),
            $this->getTypeMapper(),
            $this->getArgumentResolver(),
            $this->getTypeResolver(),
            new CachedDocBlockFactory(new ArrayCache()),
            new NamingStrategy(),
            new CompositeRootTypeMapper([
                new MyCLabsEnumTypeMapper(),
                new BaseTypeMapper($this->getTypeMapper())
            ]),
            new CompositeParameterMapper([
                new ResolveInfoParameterMapper()
            ]),
            new AuthorizationFieldMiddleware(
                new VoidAuthenticationService(),
                new VoidAuthorizationService()
            )
        );
    }

    protected function getFieldsBuilder(): FieldsBuilder
    {
        if ($this->fieldsBuilder === null) {
            $this->fieldsBuilder = $this->buildFieldsBuilder(
                $this->getAnnotationReader(),
                $this->getTypeMapper(),
                $this->getArgumentResolver(),
                new VoidAuthenticationService(),
                new VoidAuthorizationService(),
                $this->getTypeResolver(),
                new CachedDocBlockFactory(new ArrayCache()),
                new NamingStrategy(),
                new CompositeRootTypeMapper([
                    new MyCLabsEnumTypeMapper(),
                    new BaseTypeMapper($this->getTypeMapper())
                ])
            );
        }
        return $this->fieldsBuilder;
    }

    protected function getTypeGenerator(): TypeGenerator
    {
        if ($this->typeGenerator !== null) {
            return $this->typeGenerator;
        }
        $this->typeGenerator = new TypeGenerator($this->getAnnotationReader(), new NamingStrategy(), $this->getTypeRegistry(), $this->getRegistry(), $this->getTypeMapper(), $this->getFieldsBuilder());
        return $this->typeGenerator;
    }

    protected function getInputTypeGenerator(): InputTypeGenerator
    {
        if ($this->inputTypeGenerator !== null) {
            return $this->inputTypeGenerator;
        }
        $this->inputTypeGenerator = new InputTypeGenerator($this->getInputTypeUtils(), $this->getFieldsBuilder());
        return $this->inputTypeGenerator;
    }

    protected function getInputTypeUtils(): InputTypeUtils
    {
        if ($this->inputTypeUtils === null) {
            $this->inputTypeUtils = new InputTypeUtils($this->getAnnotationReader(), new NamingStrategy());
        }
        return $this->inputTypeUtils;
    }

    protected function getTypeResolver(): TypeResolver
    {
        if ($this->typeResolver === null) {
            $this->typeResolver = new TypeResolver();
            $this->typeResolver->registerSchema(new \GraphQL\Type\Schema([]));
        }
        return $this->typeResolver;
    }

    protected function getTypeRegistry(): TypeRegistry
    {
        if ($this->typeRegistry === null) {
            $this->typeRegistry = new TypeRegistry();
        }
        return $this->typeRegistry;
    }
}
