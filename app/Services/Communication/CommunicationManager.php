<?php

namespace App\Services\Communication;

use App\Contracts\Communication\EmailServiceInterface;
use App\Contracts\Communication\SmsServiceInterface;
use App\Contracts\Communication\VoiceCallServiceInterface;

class CommunicationManager
{
    public function __construct(
        protected SmsServiceInterface $smsService,
        protected VoiceCallServiceInterface $voiceCallService,
        protected EmailServiceInterface $emailService
    ) {}

    public function sms(): SmsServiceInterface
    {
        return $this->smsService;
    }

    public function voice(): VoiceCallServiceInterface
    {
        return $this->voiceCallService;
    }

    public function email(): EmailServiceInterface
    {
        return $this->emailService;
    }
}

