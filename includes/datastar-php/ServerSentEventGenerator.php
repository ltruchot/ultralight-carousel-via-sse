<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace ULCAR\Datastar;

defined( 'ABSPATH' ) || exit;

use ULCAR\Datastar\enums\ElementPatchMode;
use ULCAR\Datastar\enums\NamespaceType;
use ULCAR\Datastar\events\EventInterface;
use ULCAR\Datastar\events\ExecuteScript;
use ULCAR\Datastar\events\Location;
use ULCAR\Datastar\events\PatchElements;
use ULCAR\Datastar\events\PatchSignals;
use ULCAR\Datastar\events\RemoveElements;

class ServerSentEventGenerator
{
    /**
     * The response headers that should be sent.
     */
    public static function headers(): array
    {
        $headers = [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/event-stream',
            // Disable buffering for Nginx.
            // https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_buffering
            'X-Accel-Buffering' => 'no',
        ];

        // Connection-specific headers are only allowed in HTTP/1.1.
        // https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Connection
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to a literal below and never output, stored or used to build anything.
        $protocol = $_SERVER['SERVER_PROTOCOL'] ?? null;
        if ($protocol === 'HTTP/1.1') {
            $headers['Connection'] = 'keep-alive';
        }

        return $headers;
    }

    /**
     * Returns the signals sent in the incoming request.
     */
    public static function readSignals(): array
    {
        // Replaced while vendoring; see UPSTREAM.md. The original reads $_GET
        // and $_SERVER without guards, which can print a PHP warning into the
        // event stream. Read parameters from WP_REST_Request instead.
        throw new \RuntimeException(
            'readSignals() is not available in this vendored copy of the Datastar SDK. Read request parameters from WP_REST_Request.'
        );
    }

    /**
     * Constructor.
     */
    public function __construct()
    {
        // Abort the process if the client closes the connection.
        ignore_user_abort(false);
    }

    /**
     * Sends the response headers, if not already sent.
     */
    public function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        foreach (static::headers() as $name => $value) {
            header("$name: $value");
        }
    }

    /**
     * Patches HTML elements into the DOM and returns the resulting output.
     *
     * @param array{
     *     selector?: string|null,
     *     mode?: ElementPatchMode|string|null,
     *     namespace?: NamespaceType|string|null,
     *     useViewTransition?: bool|null,
     *     viewTransitionSelector?: string|null,
     *     eventId?: string|null,
     *     retryDuration?: int|null,
     * } $options
     */
    public function patchElements(string $elements, array $options = []): string
    {
        return $this->sendEvent(new PatchElements($elements, $options));
    }

    /**
     * Patches signals and returns the resulting output.
     */
    public function patchSignals(array|string $signals, array $options = []): string
    {
        return $this->sendEvent(new PatchSignals($signals, $options));
    }

    /**
     * Removes elements from the DOM and returns the resulting output.
     *
     * @param array{
     *      eventId?: string|null,
     *      retryDuration?: int|null,
     *  } $options
     */
    public function removeElements(string $selector, array $options = []): string
    {
        return $this->sendEvent(new RemoveElements($selector, $options));
    }

    /**
     * Executes JavaScript in the browser and returns the resulting output.
     */
    public function executeScript(string $script, array $options = []): string
    {
        return $this->sendEvent(new ExecuteScript($script, $options));
    }

    /**
     * Redirects the browser by setting the location to the provided URI and returns the resulting output.
     */
    public function location(string $uri, array $options = []): string
    {
        return $this->sendEvent(new Location($uri, $options));
    }

    /**
     * Sends an event, flushes the output buffer and returns the resulting output.
     */
    protected function sendEvent(EventInterface $event): string
    {
        $output = $event->getOutput();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this echo IS the SSE frame; escaping happened where the markup was composed, in ULCAR\Slides.
        echo $output;

        if (ob_get_contents()) {
            ob_end_flush();
        }
        flush();

        return $output;
    }
}
