<?php

declare(strict_types=1);

namespace AppTests\Factory;

use App\Factory\ProfilerFactory;
use AppTests\AbstractTestCase;
use PHPUnit\Framework\Attributes\Test;
use Waffle\Commons\Contracts\Pipeline\MiddlewareStackInterface;
use Waffle\Commons\Contracts\Telemetry\NullTracer;
use Waffle\Commons\Telemetry\Middleware\ProfilerHeadersMiddleware;
use Waffle\Commons\Telemetry\Profiler\QueryCountingTracer;

/**
 * [DXP-01] : le profileur n'existe qu'en debug — un traceur qui compte les
 * requêtes et le middleware d'en-têtes en tête de pile ; hors debug, rien ne
 * change.
 */
final class ProfilerFactoryTest extends AbstractTestCase
{
    #[Test]
    public function in_debug_the_tracer_counts_queries_and_the_headers_go_first(): void
    {
        $tracer = ProfilerFactory::tracer(new NullTracer(), true);
        self::assertInstanceOf(QueryCountingTracer::class, $tracer);

        $stack = $this->createMock(MiddlewareStackInterface::class);
        $stack->expects($this->once())->method('prepend')->with(self::isInstanceOf(ProfilerHeadersMiddleware::class));

        ProfilerFactory::register($stack, $tracer, true);
    }

    #[Test]
    public function outside_debug_nothing_is_added(): void
    {
        $inner = new NullTracer();
        self::assertSame($inner, ProfilerFactory::tracer($inner, false));

        $stack = $this->createMock(MiddlewareStackInterface::class);
        $stack->expects($this->never())->method('prepend');

        ProfilerFactory::register($stack, $inner, false);
    }
}
