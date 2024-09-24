<?php

namespace FluentBoards\Framework\Container;

use Exception;
use FluentBoards\Framework\Container\Contracts\Psr\NotFoundExceptionInterface;

class EntryNotFoundException extends Exception implements NotFoundExceptionInterface
{
    //
}
