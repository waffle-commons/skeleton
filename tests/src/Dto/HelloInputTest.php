<?php

declare(strict_types=1);

namespace AppTests\Dto;

use App\Dto\HelloInput;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Contracts\Exception\Validation\ValidationExceptionInterface;
use Waffle\Exception\ValidationException;

final class HelloInputTest extends AbstractTestCase
{
    #[Test]
    public function it_accepts_and_trims_an_alphabetic_name(): void
    {
        $dto = new HelloInput(name: '  Ada  ');

        self::assertSame('Ada', $dto->name);
    }

    #[Test]
    public function it_accepts_unicode_letters(): void
    {
        $dto = new HelloInput(name: 'Eloise');

        self::assertSame('Eloise', $dto->name);
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function it_rejects_invalid_names(string $invalid): void
    {
        $this->expectException(ValidationExceptionInterface::class);

        new HelloInput(name: $invalid);
    }

    #[Test]
    public function it_throws_a_validation_exception_carrying_the_field_name(): void
    {
        try {
            new HelloInput(name: '');
            self::fail('Expected ValidationException for empty name.');
        } catch (ValidationException $exception) {
            self::assertSame('name', $exception->getField());
            self::assertSame(422, $exception->getCode());
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'contains digits' => ['Ada123'];
        yield 'contains symbols' => ['A@a'];
        yield 'contains a space' => ['Ada Lovelace'];
    }
}
