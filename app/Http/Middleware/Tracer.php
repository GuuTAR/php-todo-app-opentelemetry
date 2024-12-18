<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\ResourceAttributes;

class Tracer
{
    private const MICRO_TO_NANO_TIME_PRECISION = 1_000_000_000;
    private const MILLI_TO_NANO_TIME_PRECISION = 1_000_000;

    private string $appname; // Service Name

    private string $dbtype; // Database type such as mysql, sql_srv, etcs.
    private string $dbname; // Database Name
    private string $dbServiceName; // Database Service Name

    private string $otlpEndpoint; // OpenTelemetry Endpoint
    private string $otlpscope; // OpenTelemetry Scope Name
    private string $otlpContentType; // OpenTelemetry Content Type
    private string $otlpIsUsingBatchProcessor; // Status for using batch processor to optimize

    private string $otelEnabled; // OpenTelemetry Enabled status

    private TracerProvider $tracer;
    private TracerProvider $tracerDb;

    public function __construct()
    {
        $this->appname = env('APP_NAME', 'Unknown');

        $this->dbtype = env('DB_CONNECTION', 'Unknown');
        $this->dbname = env('DB_DATABASE', 'Unknown');
        $this->dbServiceName = env('DB_SERVICE_NAME', 'Unknown');

        $this->otlpEndpoint = env('IBM_INSTANA_OTLP_ENDPOINT', 'http://localhost:4318/v1/traces');
        $this->otlpscope = env('IBM_INSTANA_OTLP_SCOPE', 'Unknown');
        $this->otlpContentType = env('IBM_INSTANA_OTLP_CONTENT_TYPE', 'application/json');
        $this->otlpIsUsingBatchProcessor = env('IBM_INSTANA_OTLP_USING_BATCH', false);

        $this->otelEnabled = env('IBM_INSTANA_OTEL_ENABLED', false);

        $this->tracer = $this->getTracerProvider($this->appname);
        $this->tracerDb = $this->getTracerProvider($this->dbServiceName);
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
        $starttime = microtime(true);
        try {
            if (!$this->otelEnabled) {
                $response = $next($request);
                return $response;
            }

            // Retrieve Tracing
            $parent = $this->recordHttpMetrics($request);

            // Enable query logging
            $this->recordSQLStatement($parent);

            $requestStarttime = microtime(true);

            // Process the request
            // Pass the request to the next middleware/controller
            $response = $next($request);

            $requestEndtime = microtime(true);
            $requestTime = $requestEndtime - $requestStarttime;

            $parent->setAttribute('http.status_code', $response->getStatusCode());

            // Get controller and method information
            $this->recordControllerInfo($parent);
        } catch (\Exception $e) {
            // Capture any exception that occurs during the request
            $parent->recordException($e);
            $parent->setAttribute('error', true);

            throw $e; // Continue propagating the exception
        }
        $endtime = microtime(true);
        $totalTime = $endtime - $starttime;

        $traceTime = $totalTime - $requestTime;
        $traceTimePercent = ($traceTime * 100) / $totalTime;

        // Stop the span for tracing the request
        $parent->setAttribute('http.total_time', $totalTime);
        $parent->setAttribute('tracing.time_s', $traceTime);
        $parent->setAttribute('tracing.time_percent', $traceTimePercent);
        $parent->setAttribute('tracing.processor', $this->otlpIsUsingBatchProcessor ? 'batch' : 'simple');
        $parent->end();

        $this->tracer->shutdown();
        $this->tracerDb->shutdown();
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

    private function recordHttpMetrics(Request $request): SpanInterface
    {
        // Start the span for tracing the request
        $span = $this->tracer
            ->getTracer($this->otlpscope)
            ->spanBuilder("{$request->getMethod()} {$request->getRequestUri()}")
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->setAttribute('http.method', $request->getMethod());
        $span->setAttribute('http.url', $request->fullUrl());
        $span->setAttribute('http.scheme', $request->getScheme());
        $span->setAttribute('http.host', $request->getHost());
        $span->setAttribute('http.target', $request->getRequestUri());
        $span->setAttribute('http.route', $request->getRequestUri());
        $span->setAttribute('http.user_agent', $request->header('User-Agent'));

        return $span;
    }

    private function getDbOperation($dbStatement): string
    {
        if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE|REPLACE)\b/i', $dbStatement, $matches)) {
            return strtoupper($matches[1]);
        }
        return 'UNKNOWN';
    }

    private function recordSQLStatement(SpanInterface $parent): SpanInterface
    {
        DB::listen(function ($query) use ($parent) {
            // Retrieve SQL Statement
            $scope = $parent->activate();

            $endQueryTimeNano = (int) (microtime(true) * self::MICRO_TO_NANO_TIME_PRECISION);
            $timeDiff = (int) ($query->time * self::MILLI_TO_NANO_TIME_PRECISION);
            $startQueryTimeNano = $endQueryTimeNano - $timeDiff;

            // Retrieve query span
            $querySpan = $this->tracerDb
                ->getTracer($this->otlpscope)
                ->spanBuilder($query->sql)
                ->setStartTimestamp($startQueryTimeNano)
                ->startSpan();

            // Retrieve database metrics
            $querySpan->setAttribute('db.statement', $query->sql);
            $querySpan->setAttribute('db.operation', $this->getDbOperation($query->sql));
            $querySpan->setAttribute('db.params', json_encode($query->bindings));
            $querySpan->setAttribute('db.system', $this->dbtype);
            $querySpan->setAttribute('db.name', $this->dbname);
            $querySpan->setAttribute('db.execution_time_ms', $query->time);
            $querySpan->setAttribute('service.name', $this->dbServiceName);

            // End the query span
            $querySpan->end($endQueryTimeNano);

            $scope->detach();
        });

        DB::flushQueryLog();

        return $parent;
    }

    private function getTracerProvider(string $serviceName): TracerProvider
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
                $this->otlpIsUsingBatchProcessor ?
                    (new BatchSpanProcessorBuilder($spanExporter))->build() :
                    new SimpleSpanProcessor($spanExporter)
            )
            ->setResource($resource)
            ->build();
        return $tracerProvider;
    }
}
