<?php

namespace Carsdotcom\LaravelJsonModel\Exceptions;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Class UniqueException
 * @package Carsdotcom\LaravelJsonModel\Exceptions
 */
class UniqueException extends HttpException
{
    /**
     * Create an exception, descended from HttpException for throwing.
     * @param string $message
     */
    public function __construct(string $message)
    {
        parent::__construct(
            Response::HTTP_BAD_REQUEST,
            $message
        );
    }
}
