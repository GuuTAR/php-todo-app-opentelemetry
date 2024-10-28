<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

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
use OpenTelemetry\API\Trace\SpanInterface;

class Tracer
{
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
        $body = $request->input();
        $query = $request->query();

        // Start the span for tracing the request
        $appname = env('APP_NAME', 'Unknown');

        $parent = $this->getTracerProvider($appname)->spanBuilder("HTTP $method $path")->startSpan();
        $parent->setAttribute('http.method', $method);
        $parent->setAttribute('http.url', $path);
        $parent->setAttribute('http.body', $body);
        $parent->setAttribute('http.query', $query);

        // Process the request
        try {
            // Enable query logging
            DB::enableQueryLog();

            // Pass the request to the next middleware/controller
            $response = $next($request);

            // Retrieve executed queries after the request
            $queries = DB::getQueryLog();
            DB::flushQueryLog();

            $this->getSQLStatement($parent, $queries);

            // Set HTTP status code in the trace
            $parent->setAttribute('http.status_code', $response->getStatusCode());
        } catch (\Exception $e) {
            // Capture any exception that occurs during the request
            $parent->recordException($e);
            $parent->setAttribute('error', true);

            throw $e; // Continue propagating the exception
        }

        $parent->end();
        return $response;
    }

    private function getSQLStatement(SpanInterface $parent, array $queries)
    {
        // Retrieve the current parent span
        $scope = $parent->activate();

        $dbtype = env('DB_CONNECTION', 'Unknown');
        $dbname = env('DB_DATABASE', 'Unknown');

        $tracer = $this->getTracerProvider($dbtype);

        try {
            foreach ($queries as $query) {
                // Start a new span for each SQL query
                $querySpan = $tracer->spanBuilder("SQL Statement")->startSpan();
                $querySpan->setAttribute('db.statement', $query['query']);
                $querySpan->setAttribute('db.params', json_encode($query['bindings']));
                $querySpan->setAttribute('db.type', $dbtype);
                $querySpan->setAttribute('db.name', $dbname);
                $querySpan->setAttribute('db.execution_time_ms', $query['time']);
                // End the query span
                $querySpan->end();
            }
        } finally {
            $scope->detach();
        }
    }

    private function getTracerProvider(string $serviceName)
    {
        $resource = ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => $serviceName,
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

        return $tracerProvider->getTracer(
            'WEBINTRA-ZONE'
        );
    }
}
