<?php

namespace App\Exceptions;

class NumberAlreadyAllocatedException extends NumberAllocationException
{
    public function __construct(string $number)
    {
        parent::__construct(__('exceptions.number_already_allocated', ['number' => $number]));
    }
}