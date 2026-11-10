<?php

namespace App\Enums;

enum CryptoNetwork: string
{
    case Tron = 'TRON';
    case Trc20 = 'TRC20';
    case Erc20 = 'ERC20';
    case Bep20 = 'BEP20';

    public function label(): string
    {
        return __('enums.crypto_network.' . $this->value);
    }

    public function defaultCurrency(): string
    {
        return match ($this) {
            self::Tron => 'TRX',
            self::Trc20 => 'USDT',
            self::Erc20 => 'ETH',
            self::Bep20 => 'BNB',
        };
    }

    public function isTronBased(): bool
    {
        return $this === self::Tron || $this === self::Trc20;
    }
}