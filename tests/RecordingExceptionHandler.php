<?php

namespace Dercol1\LibrenmsIpam\Tests;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the last exception the kernel passed it.
 *
 * The end-to-end render test drives the http kernel directly, so a throwing
 * page answers 500 and the failure message would say only "500 is not 200",
 * which names the symptom and hides the cause. Bound around the app's own
 * handler, this keeps everything else identical and records the message.
 */
class RecordingExceptionHandler implements ExceptionHandler
{
    private ?\Throwable $last = null;

    public function __construct(private readonly ExceptionHandler $inner) {}

    public function lastMessage(): string
    {
        return $this->last === null
            ? 'no exception recorded'
            : $this->last::class . ': ' . $this->last->getMessage();
    }

    public function report(\Throwable $e): void
    {
        $this->last = $e;
        $this->inner->report($e);
    }

    public function shouldReport(\Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, \Throwable $e): Response
    {
        $this->last = $e;

        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, \Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }
}
