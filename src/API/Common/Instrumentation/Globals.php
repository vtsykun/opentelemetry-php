<?php

declare(strict_types=1);

namespace OpenTelemetry\API\Common\Instrumentation;

use function assert;
use Closure;
use const E_USER_WARNING;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use function sprintf;
use Throwable;
use function trigger_error;

/**
 * Provides access to the globally configured instrumentation instances.
 */
final class Globals
{
    /** @var list<Closure(Configurator): Configurator> */
    private static array $initializers = [];
    private static ?self $globals = null;

    private TracerProviderInterface $tracerProvider;
    private MeterProviderInterface $meterProvider;
    private TextMapPropagatorInterface $propagator;

    public function __construct(
        TracerProviderInterface $tracerProvider,
        MeterProviderInterface $meterProvider,
        TextMapPropagatorInterface $propagator
    ) {
        $this->tracerProvider = $tracerProvider;
        $this->meterProvider = $meterProvider;
        $this->propagator = $propagator;
    }

    public static function tracerProvider(): TracerProviderInterface
    {
        return Context::getCurrent()->get(ContextKeys::tracerProvider()) ?? self::globals()->tracerProvider;
    }

    public static function meterProvider(): MeterProviderInterface
    {
        return Context::getCurrent()->get(ContextKeys::meterProvider()) ?? self::globals()->meterProvider;
    }

    public static function propagator(): TextMapPropagatorInterface
    {
        return Context::getCurrent()->get(ContextKeys::propagator()) ?? self::globals()->propagator;
    }

    /**
     * @param Closure(Configurator): Configurator $initializer
     *
     * @interal
     * @psalm-internal OpenTelemetry
     */
    public static function registerInitializer(Closure $initializer): void
    {
        self::$initializers[] = $initializer;
    }

    /**
     * @phan-suppress PhanTypeMismatchReturnNullable
     */
    private static function globals(): self
    {
        if (self::$globals !== null) {
            return self::$globals;
        }

        $configurator = Configurator::createNoop();
        $scope = $configurator->activate();

        try {
            foreach (self::$initializers as $initializer) {
                try {
                    $configurator = $initializer($configurator);
                } catch (Throwable $e) {
                    trigger_error(sprintf("Error during opentelemetry initialization: %s\n%s", $e->getMessage(), $e->getTraceAsString()), E_USER_WARNING);
                }
            }
        } finally {
            $scope->detach();
        }

        $context = $configurator->storeInContext();
        $tracerProvider = $context->get(ContextKeys::tracerProvider());
        $meterProvider = $context->get(ContextKeys::meterProvider());
        $propagator = $context->get(ContextKeys::propagator());

        assert(isset($tracerProvider, $meterProvider, $propagator));

        return self::$globals = new self($tracerProvider, $meterProvider, $propagator);
    }

    /**
     * @internal
     */
    public static function reset(): void
    {
        self::$globals = null;
    }
}
