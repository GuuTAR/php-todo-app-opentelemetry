<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
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
    private string $appname; // Service Name
    private string $dbtype; // Database type such as mysql, sql_srv, etcs.
    private string $dbname; // Database Name
    private string $dbServiceName; // Database Service Name
    private string $otlpEndpoint; // OpenTelemetry Endpoint
    private string $otlpscope; // OpenTelemetry Scope Name
    private string $otlpContentType; // OpenTelemetry Content Type
    private string $otelEnabled; // OpenTelemetry Enabled status

    public function __construct()
    {
        $this->appname = env('APP_NAME', 'Unknown');
        $this->dbtype = env('DB_CONNECTION', 'Unknown');
        $this->dbname = env('DB_DATABASE', 'Unknown');
        $this->dbServiceName = env('DB_SERVICE_NAME', 'Unknown');
        $this->otlpEndpoint = env('IBM_INSTANA_OTLP_ENDPOINT', 'http://localhost:4318/v1/traces');
        $this->otlpscope = env('IBM_INSTANA_OTLP_SCOPE', 'Unknown');
        $this->otlpContentType = env('IBM_INSTANA_OTLP_CONTENT_TYPE', 'application/json');
        $this->otelEnabled = env('IBM_INSTANA_OTEL_ENABLED', false);
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
        try {
            if (!$this->otelEnabled) {
                $response = $next($request);
                return $response;
            }


            // Enable query logging
            DB::enableQueryLog();

            // Process the request
            // Pass the request to the next middleware/controller
            $response = $next($request);

            // Retrieve executed queries after the request
            $queries = DB::getQueryLog();
            DB::flushQueryLog();

            // Retrieve Tracing
            $parent = $this->recordHttpMetrics($request);
            $parent->setAttribute('http.status_code', $response->getStatusCode());

            // Get controller and method information
            $this->recordControllerInfo($parent);

            // Retrieve SQL Statement
            $this->recordSQLStatement($parent, $queries);

            // Stop the span for tracing the request
            $parent->end();
        } catch (\Exception $e) {
            // Capture any exception that occurs during the request
            $parent->recordException($e);
            $parent->setAttribute('error', true);

            throw $e; // Continue propagating the exception
        }
        return $response;
    }

    private function recordControllerInfo(SpanInterface $span): void
    {
        $route = Route::current();

        if ($route) {
            $action = $route->getAction();
            $controller = $action['controller'] ?? 'Unknown';

            // Get controller and method separately
            if (is_string($controller) & $controller != 'Unknown') {
                [$controllerName, $methodName] = explode('@', $controller);
                $span->setAttribute('controller.name', class_basename($controllerName));
                $span->setAttribute('controller.method', $methodName);
            }
        }
    }

    private function recordHttpMetrics(Request $request)
    {
        // Start the span for tracing the request
        $span = $this->getTracerProvider($this->appname)
            ->spanBuilder("{$request->getMethod()} {$request->getRequestUri()}")
            ->startSpan();

        $span->setAttribute('span.kind', 'HTTP');
        $span->setAttribute('http.method', $request->getMethod());
        $span->setAttribute('http.url', $request->fullUrl());
        $span->setAttribute('http.scheme', $request->getScheme());
        $span->setAttribute('http.host', $request->getHost());
        $span->setAttribute('http.target', $request->getRequestUri());
        $span->setAttribute('http.route', $request->route()->uri() ?? 'Unknown');
        $span->setAttribute('http.user_agent', $request->header('User-Agent'));

        return $span;
    }

    private function getDbOperation($dbStatement)
    {
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE|REPLACE)\b/i', $dbStatement, $matches)) {
            return strtoupper($matches[1]);
        }
        return 'UNKNOWN';
    }



    private function recordSQLStatement(SpanInterface $parent, array $queries)
    {
        // Retrieve the current parent span
        $scope = $parent->activate();
        $tracer = $this->getTracerProvider($this->dbtype);

        foreach ($queries as $query) {
            // Start a new span for each SQL query
            $querySpan = $tracer->spanBuilder($query['query'])->startSpan();

            // Retrieve database metrics
            $querySpan->setAttribute('db.statement', $query['query']);
            $querySpan->setAttribute('db.operation', $this->getDbOperation($query['query']));
            $querySpan->setAttribute('db.params', json_encode($query['bindings']));
            $querySpan->setAttribute('db.system', $this->dbtype);
            $querySpan->setAttribute('db.name', $this->dbname);
            $querySpan->setAttribute('db.execution_time_ms', $query['time']);
            $querySpan->setAttribute('service.name', $this->dbServiceName);

            // End the query span
            $querySpan->end();
        }
        $scope->detach();
    }

    private function getTracerProvider(string $serviceName)
    {
        // Initialize Resource Infomation
        $resource = ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create([
            ResourceAttributes::SERVICE_NAME => $serviceName,
        ])));

        // Get Exporter 
        $transport = (new OtlpHttpTransportFactory())->create($this->otlpEndpoint, $this->otlpContentType);
        $spanExporter = new SpanExporter($transport);

        // Create Tracer Provider 
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(
                new SimpleSpanProcessor($spanExporter)
            )
            ->setResource($resource)
            ->setSampler(new ParentBased(new AlwaysOnSampler()))
            ->build();

        return $tracerProvider->getTracer($this->otlpscope);
    }
}
