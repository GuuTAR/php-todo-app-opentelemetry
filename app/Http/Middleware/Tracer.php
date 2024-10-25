<?php

namespace App\Http\Middleware;

use Closure;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Tracer
{
    private TracerInterface $tracer;

    public function __construct()
    {
        $resource = ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAMESPACE => 'TODO',
            ResourceAttributes::SERVICE_NAME => 'TODO-APP',
            ResourceAttributes::SERVICE_VERSION => '0.1',
            ResourceAttributes::DEPLOYMENT_ENVIRONMENT => 'development',
        ])));

        $transport = (new OtlpHttpTransportFactory())->create('http://localhost:4318/v1/traces', 'application/json');

        $spanExporter = new SpanExporter(
            $transport
        );

        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(
                new SimpleSpanProcessor($spanExporter)
            )
            ->setResource($resource)
            ->setSampler(new ParentBased(new AlwaysOnSampler()))
            ->build();

        $this->tracer = $tracerProvider->getTracer(
            'TODO',
            '0.1.0',
            'https://opentelemetry.io/schemas/1.24.0'
        );
    }
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        // Create a new span for the incoming request
        $path = $request->fullUrl();
        $method = $request->getMethod();

        // Start the span for tracing the request
        $span = $this->tracer->spanBuilder("HTTP $method $path")->startSpan();
        $span->setAttribute('http.method', $method);
        $span->setAttribute('http.url', $path);

        // Process the request
        try {
            // Pass the request to the next middleware/controller
            $response = $next($request);

            // Set HTTP status code in the trace
            $span->setAttribute('http.status_code', $response->getStatusCode());
        } catch (\Exception $e) {
            // Capture any exception that occurs during the request
            $span->recordException($e);
            $span->setAttribute('error', true);

            throw $e; // Continue propagating the exception
        } finally {
            // End the span
            $span->end();
        }

        return $response;
    }
}
