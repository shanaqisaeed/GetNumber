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
        return match ($this) {
            self::Tron => 'شبکه ترون (TRON / TRX)',
            self::Trc20 => 'شبکه ترون (TRC-20)',
            self::Erc20 => 'شبکه اتریوم (ERC-20)',
            self::Bep20 => 'شبکه بایننس اسمارت چین (BEP-20)',
        };
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

