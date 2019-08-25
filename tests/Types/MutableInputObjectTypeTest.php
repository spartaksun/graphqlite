<?php

namespace TheCodingMachine\GraphQLite\Types;

use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MutableInputObjectTypeTest extends TestCase
{
    /**
     * @var MutableInputObjectType
     */
    private $type;

    public function setUp(): void
    {
        $this->type = new MutableInputObjectType([
            'name'    => 'TestObject',
            'fields'  => [
                'test'   => Type::string(),
            ],
        ]);
    }

    public function testGetStatus(): void
    {
        $this->assertSame(MutableInputObjectType::STATUS_PENDING, $this->type->getStatus());
        $this->type->freeze();
        $this->assertSame(MutableInputObjectType::STATUS_FROZEN, $this->type->getStatus());
    }

    public function testAddFields(): void
    {
        $this->type->addFields(function() {
            return [
                'test'   => Type::int(),
                'test2'   => Type::string(),
            ];
        });
        $this->type->addFields(function() {
            return [
                'test3'   => Type::int(),
            ];
        });
        $this->type->freeze();
        $fields = $this->type->getFields();
        $this->assertCount(3, $fields);
        $this->assertArrayHasKey('test', $fields);
        $this->assertSame(Type::int(), $fields['test']->getType());
    }

    public function testGetField(): void
    {
        $this->type->freeze();
        $this->assertSame(Type::string(), $this->type->getField('test')->getType());
    }

    public function testGetFieldError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->type->getField('test');
    }

    public function testGetFieldsError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->type->getFields();
    }

    public function testAddFieldsError(): void
    {
        $this->type->freeze();
        $this->expectException(RuntimeException::class);
        $this->type->addFields(function() {});
    }

    public function testNoFieldsType(): void
    {
        $type = new MutableInputObjectType([
            'name'    => 'TestObject',
            'fields'  => [],
        ]);
        $type->freeze();
        $this->expectException(NoFieldsException::class);
        $this->expectExceptionMessage('The GraphQL object type "TestObject" has no fields defined. Please check that some fields are defined (using the @Field annotation). If some fields are defined, please check that at least one is visible to the current user.');
        $type->getFields();
    }
}
