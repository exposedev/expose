<?php

namespace Expose\Client\Logger;

use Expose\Client\Logger\Plugins\PluginData;
use Carbon\Carbon;
use Exception;
use Expose\Client\Logger\Plugins\PluginManager;
use Expose\Client\RequestLog;
use GuzzleHttp\Psr7\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Http\Header\GenericHeader;
use Laminas\Http\Header\MultipleHeaderInterface;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Namshi\Cuzzle\Formatter\CurlFormatter;
use Riverline\MultiPartParser\StreamedPart;

class LoggedRequest implements \JsonSerializable
{
    /** @var string */
    protected $rawRequest;

    /** @var Request */
    public $parsedRequest;

    /** @var LoggedResponse */
    protected $response;

    /** @var string */
    protected $id;

    /** @var Carbon */
    protected $startTime;

    /** @var Carbon */
    protected $stopTime;

    /** @var string */
    protected $subdomain;

    protected ?PluginData $pluginData = null;

    public function __construct(string $rawRequest, Request $parsedRequest)
    {
        $this->startTime = now();
        $this->rawRequest = $rawRequest;
        $this->parsedRequest = $parsedRequest;
        $this->id = $this->getRequestId();

        $this->pluginData = app(PluginManager::class)->loadPluginData($this);
    }

    /**
     * {@inheritdoc}
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        $data = [
            'id' => $this->id,
            'performed_at' => $this->startTime->toDateTimeString(),
            'duration' => $this->getDuration(),
            'subdomain' => $this->detectSubdomain(),
            'request' => [
                'raw' => $this->isBinary($this->rawRequest) ? 'BINARY' : $this->rawRequest,
                'method' => $this->parsedRequest->getMethod(),
                'uri' => $this->parsedRequest->getUriString(),
                'headers' => $this->getRequestHeaders(),
                'body' => $this->isBinary($this->rawRequest) ? 'BINARY' : $this->parsedRequest->getContent(),
                'query' => $this->parsedRequest->getQuery()->toArray(),
                'post' => $this->getPostData(),
                'curl' => $this->getRequestAsCurl(),
                'plugin' => $this->getPluginData()
            ],
        ];

        if ($this->response) {
            $data['response'] = $this->response->toArray();
        }

        return $data;
    }

    /**
     * Laminas HTTP might throw an "InvalidUriException" when
     * parsing headers.
     *
     * We simply ignore those invalid headers instead of
     * crashing.
     *
     * @return array
     */
    protected function getRequestHeaders(): array
    {
        $headers = [];
        $requestHeaders = $this->parsedRequest->getHeaders();

        // Laminas parses each header lazily while iterating, so a malformed
        // header (an empty "Cookie:" for example) throws in current().
        for ($requestHeaders->rewind(); $requestHeaders->valid(); $requestHeaders->next()) {
            try {
                $header = $requestHeaders->current();
            } catch (\Throwable $e) {
                continue;
            }

            if ($header instanceof MultipleHeaderInterface) {
                $name = $header->getFieldName();
                if (! isset($headers[$name])) {
                    $headers[$name] = [];
                }
                try {
                    $headers[$name][] = $header->getFieldValue();
                } catch (\Throwable $e) {
                    $headers[$name] = 'invalid';
                }
            } else {
                try {
                    $headers[$header->getFieldName()] = $header->getFieldValue();
                } catch (\Throwable $e) {
                    $headers[$header->getFieldName()] = 'invalid';
                }
            }
        }

        return $headers;
    }

    public function toDatabase(): array {

        return [
            'request_id' => $this->id,
            'subdomain' => $this->detectSubdomain(),
            'raw_request' => $this->rawRequest,
            'request_method' => $this->parsedRequest->getMethod(),
            'request_uri' => $this->parsedRequest->getUriString(),
            'start_time' => $this->startTime->getTimestampMs(),
            'stop_time' => $this->stopTime?->getTimestampMs(),
            'performed_at' => $this->startTime->toDateTimeString(),
            'duration' => $this->getDuration(),
            'plugin_data' => $this->pluginData ? json_encode($this->pluginData->toArray()) : null,
        ];
    }

    public static function fromRecord(RequestLog $requestLog): self {
        $loggedRequest = new self($requestLog->raw_request, Request::fromString($requestLog->raw_request));
        $loggedRequest->id = $requestLog->request_id;
        $loggedRequest->startTime = Carbon::createFromTimestampMs($requestLog->start_time);
        $loggedRequest->stopTime = $requestLog->stop_time ? Carbon::createFromTimestampMs($requestLog->stop_time) : null;
        $loggedRequest->subdomain = $requestLog->subdomain;
        $loggedRequest->pluginData = $requestLog->plugin_data ? PluginData::fromJson($requestLog->plugin_data) : null;

        return $loggedRequest;
    }

    public function toArray(): array {
        return $this->jsonSerialize();
    }

    protected function isBinary(string $string): bool
    {
        return preg_match('~[^\x20-\x7E\t\r\n]~', $string) > 0;
    }

    public function getRequest()
    {
        return $this->parsedRequest;
    }

    public function setResponse(string $rawResponse, Response $response)
    {
        $this->response = new LoggedResponse($rawResponse, $response, $this->getRequest());
    }

    public function setStopTime() {
        if (is_null($this->stopTime)) {
            $this->stopTime = now();
        }
    }

    public function id(): string
    {
        return (string)$this->id;
    }

    public function getRequestData(): ?string
    {
        return $this->rawRequest;
    }

    public function getResponse(): ?LoggedResponse
    {
        return $this->response;
    }

    public function getPostData()
    {
        $postData = [];

        $contentType = Arr::get($this->getRequestHeaders(), 'Content-Type');
        if ($contentType && Str::contains($contentType, ";")) {
            $contentType = explode(';', $contentType, 2);
            if (is_array($contentType) && count($contentType) > 1) {
                $contentType = $contentType[0];
            }
        }

        switch ($contentType) {
            case 'application/x-www-form-urlencoded':
                parse_str($this->parsedRequest->getContent(), $postData);
                $postData = collect($postData)->map(function ($value, $key) {
                    return [
                        'name' => $key,
                        'value' => $value,
                    ];
                })->toArray();
                break;
            case 'application/json':
                $postData = collect(json_decode($this->parsedRequest->getContent(), true))->map(function ($value, $key) {
                    return [
                        'name' => $key,
                        'value' => $value,
                    ];
                })->values()->toArray();

                break;
            default:
                $stream = fopen('php://temp', 'rw');
                fwrite($stream, $this->rawRequest);
                rewind($stream);

                try {
                    $document = new StreamedPart($stream);
                    if ($document->isMultiPart()) {
                        $postData = collect($document->getParts())->map(function (StreamedPart $part) {
                            return [
                                'name' => $part->getName(),
                                'value' => $part->isFile() ? null : $part->getBody(),
                                'is_file' => $part->isFile(),
                                'filename' => $part->isFile() ? $part->getFileName() : null,
                                'mime_type' => $part->isFile() ? $part->getMimeType() : null,
                            ];
                        })->toArray();
                    }
                } catch (\Exception $e) {
                    //
                }
                break;
        }

        return $postData;
    }

    public function detectSubdomain()
    {
        return collect($this->getRequestHeaders())
            ->mapWithKeys(function ($value, $key) {
                return [strtolower($key) => $value];
            })->get('x-original-host');
    }

    protected function getRequestId()
    {
        return collect($this->getRequestHeaders())
            ->mapWithKeys(function ($value, $key) {
                return [strtolower($key) => $value];
            })->get('x-expose-request-id', (string) Str::uuid());
    }

    public function getStartTime()
    {
        return $this->startTime;
    }

    public function getDuration(): int
    {
        return (int) $this->startTime->diffInMilliseconds($this->stopTime, false);
    }

    protected function getRequestAsCurl(): string
    {
        $maxRequestLength = 256000;

        if (strlen($this->rawRequest) > $maxRequestLength) {
            return '';
        }

        try {
            return (new CurlFormatter())->format(Message::parseRequest($this->rawRequest));
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function refreshId()
    {
        $requestId = (string) Str::uuid();

        $this->getRequest()->getHeaders()->removeHeader(
            $this->getRequest()->getHeader('x-expose-request-id')
        );

        $this->getRequest()->getHeaders()->addHeader(new GenericHeader('x-expose-request-id', $requestId));

        $this->id = $requestId;
    }

    public function getCliLabel(): string {
        return $this->pluginData ? $this->pluginData->getCliLabel() : '';
    }

    public function getPluginData(): ?array {
        return $this->pluginData ? $this->pluginData->toArray() : null;
    }
}
