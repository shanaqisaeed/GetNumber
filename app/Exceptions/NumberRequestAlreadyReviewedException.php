<?php

namespace App\Exceptions;

class NumberRequestAlreadyReviewedException extends NumberAllocationException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('exceptions.number_request_already_reviewed'));
    }
}