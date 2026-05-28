<?php

declare(strict_types=1);

namespace App\Service;

final class DemoService
{
    /**
     * @return string[]
     */
    public function sayHello(?string $to = null): array
    {
        $name = $to ?? 'from Waffle';

        return [
            'message' => "Hello {$name}!",
        ];
    }
}
