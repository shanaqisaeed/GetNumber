<?php

namespace App\Enums;

enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return __('enums.message_direction.' . $this->value);
    }
}