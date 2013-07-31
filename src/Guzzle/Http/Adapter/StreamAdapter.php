<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\ResponseInterface;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Stream\Stream;

/**
 * HTTP adapter that uses PHP's HTTP stream wrapper
 */
class StreamAdapter extends AbstractAdapter
{
    public function send(array $requests)
    {
        $result = new Transaction();
        foreach ($requests as $request) {
            try {
                $result[$request] = $this->createResponse($request);
            } catch (RequestException $e) {
                $result[$request] = $e;
            }
        }

        return $result;
    }

    /**
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws \LogicException if you attempt to stream and specify a write_to option
     */
    private function createResponse(RequestInterface $request)
    {
        $stream = $this->createStream($request, $http_response_header);
        $response = $this->messageFactory->createResponse();

        // Track the response headers of the request
        if (isset($http_response_header)) {
            $this->processResponseHeaders($http_response_header, $response);
        }

        if ($request->getTransferOptions()['stream']) {
            $this->applyStreamingBody($request, $response, $stream);
        } else{
            $this->applySaveToBody($request, $response, $stream);
        }

        return $response;
    }

    /**
     * Configure the response to use a streaming body
     * @throws \LogicException when using both the stream and save_to option
     */
    private function applyStreamingBody(RequestInterface $request, ResponseInterface $response, $stream)
    {
        // Streaming response, so do not read up front
        if ($request->getTransferOptions()['save_to']) {
            throw new \LogicException('Cannot stream a response and write to a destination simultaneously');
        }
        $response->setBody($stream);
    }

    /**
     * Drain the steam into the destination stream
     */
    private function applySaveToBody(RequestInterface $request, ResponseInterface $response, $stream)
    {
        if ($saveTo = $request->getTransferOptions()['save_to']) {
            // Stream the response into the destination stream
            $saveTo = is_string($saveTo) ? Stream::factory(fopen($saveTo, 'w')) : Stream::factory($saveTo);
        } else {
            // Stream into the default temp stream
            $saveTo = Stream::factory();
        }

        while (!feof($stream)) {
            $saveTo->write(fread($stream, 8096));
        }
        fclose($stream);
        $response->setBody($saveTo);
    }

    private function processResponseHeaders($headers, ResponseInterface $response)
    {
        $parts = explode(' ', array_shift($headers), 3);
        $response->setProtocolVersion(substr($parts[0], -3));
        $response->setStatus($parts[1], isset($parts[2]) ? $parts[2] : null);

        // Set the size on the stream if it was returned in the response
        foreach ($headers as $header) {
            $parts = explode(':', $header, 2);
            $response->addHeader($parts[0], isset($parts[1]) ? $parts[1] : '');
        }
    }

    /**
     * Create a resource and check to ensure it was created successfully
     *
     * @param callable         $callback Closure to invoke that must return a valid resource
     * @param RequestInterface $request  Request used when throwing exceptions
     * @param array            $options  Options used when throwing exceptions
     *
     * @return resource
     * @throws RequestException on error
     */
    private function createResource(callable $callback, RequestInterface $request, $options)
    {
        // Turn off error reporting while we try to initiate the request
        $level = error_reporting(0);
        $resource = call_user_func($callback);
        error_reporting($level);

        // If the resource could not be created, then grab the last error and throw an exception
        if (false === $resource) {
            $message = 'Error creating resource. [url] ' . $request->getUrl() . ' ';
            if (isset($options['http']['proxy'])) {
                $message .= "[proxy] {$options['http']['proxy']} ";
            }
            foreach (error_get_last() as $key => $value) {
                $message .= "[{$key}] {$value} ";
            }

            throw new RequestException(trim($message), $request);
        }

        return $resource;
    }

    /**
     * Create the stream for the request with the context options
     *
     * @param RequestInterface $request              Request being sent
     * @param mixed            $http_response_header Value is populated by stream wrapper
     *
     * @return resource
     */
    private function createStream(RequestInterface $request, &$http_response_header)
    {
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        $options = ['http' => [
            'method' => $request->getMethod(),
            'header' => (string) $request->getHeaders(),
            'protocol_version' => '1.0',
            'ignore_errors' => true,
            'follow_location' => 0,
            'content' => (string) $request->getBody()
        ]];

        foreach ($request->getTransferOptions()->toArray() as $key => $value) {
            $method = "visit_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $options, $value);
            }
        }

        $context = $this->createResource(function () use ($request, $options) {
            return stream_context_create($options);
        }, $request, $options);

        return $this->createResource(function () use ($request, &$http_response_header, $context) {
            return fopen($request->getUrl(), 'r', null, $context);
        }, $request, $options);
    }

    private function visit_proxy(RequestInterface $request, &$options, $value)
    {
        $options['http']['proxy'] = $value;
    }

    private function visit_timeout(RequestInterface $request, &$options, $value)
    {
        $options['http']['timeout'] = $value;
    }

    private function visit_verify(RequestInterface $request, &$options, $value)
    {
        if ($value === true || is_string($value)) {
            $options['http']['verify_peer'] = true;
            if ($value !== true) {
                $options['http']['allow_self_signed'] = true;
                $options['http']['cafile'] = $value;
            }
        } elseif ($value === false) {
            $options['http']['verify_peer'] = false;
        }
    }

    private function visit_cert(RequestInterface $request, &$options, $value)
    {
        if (is_array($value)) {
            $options['http']['local_cert'] = $value[0];
            $options['http']['passphrase'] = $value[1];
        } else {
            $options['http']['local_cert'] = $value;
        }
    }
}