<?php

declare(strict_types=1);

namespace AppTests\Service;

use App\Service\DemoService;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Service de démonstration : la salutation par défaut et la salutation nommée
 * sont les deux seules branches du contrat.
 */
final class DemoServiceTest extends AbstractTestCase
{
    #[Test]
    public function say_hello_falls_back_to_the_default_recipient(): void
    {
        self::assertSame(['message' => 'Hello from Waffle!'], new DemoService()->sayHello());
    }

    #[Test]
    public function say_hello_greets_the_given_recipient(): void
    {
        self::assertSame(['message' => 'Hello Ada!'], new DemoService()->sayHello('Ada'));
    }
}
