<?php

declare(strict_types=1);

namespace LaravelModularDDD\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use LaravelModularDDD\Core\Domain\ValueObject;

class ValueObjectTest extends TestCase
{
    /** @test */
    public function it_compares_value_objects_for_equality(): void
    {
        $vo1 = new TestValueObject('test', 123);
        $vo2 = new TestValueObject('test', 123);
        $vo3 = new TestValueObject('different', 123);

        $this->assertTrue($vo1->equals($vo2));
        $this->assertFalse($vo1->equals($vo3));
    }

    /** @test */
    public function it_converts_to_string(): void
    {
        $vo = new TestValueObject('test', 123);
        $expected = '{"name":"test","value":123}';

        $this->assertEquals($expected, $vo->toString());
        $this->assertEquals($expected, (string) $vo);
    }

    /** @test */
    public function it_ensures_immutability(): void
    {
        $vo = new TestValueObject('test', 123);

        // Value objects should not have setters - this is enforced by design
        $this->assertFalse(method_exists($vo, 'setName'));
        $this->assertFalse(method_exists($vo, 'setValue'));
    }

    /** @test */
    public function it_handles_json_encoding_errors_gracefully(): void
    {
        // Create a value object with non-UTF8 data that will cause JSON encoding to fail
        $vo = new class extends ValueObject {
            public function toArray(): array
            {
                return ['invalid' => "\xB1\x31"]; // Invalid UTF-8 sequence
            }
        };

        $this->expectException(\JsonException::class);
        $vo->toString();
    }
}

class TestValueObject extends ValueObject
{
    public function __construct(
        private readonly string $name,
        private readonly int $value
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
        ];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): int
    {
        return $this->value;
    }
}