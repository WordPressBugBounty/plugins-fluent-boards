<?php

namespace FluentBoards\Framework\Container\Contracts;

use Exception;
use FluentBoards\Framework\Container\Contracts\Psr\ContainerExceptionInterface;

class CircularDependencyException extends Exception implements ContainerExceptionInterface
{
    //
}
