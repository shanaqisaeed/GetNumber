<?php

namespace App\Services\Numbers;

use App\Enums\PhoneNumberStatus;
use App\Enums\PhoneNumberType;
use App\Models\PhoneNumber;
use App\Models\PhonePrefix;
use App\Models\User;

class NumberSearchAndSuggestionService
{
    public function __construct(
        protected RondPatternDetectorService $rondDetector
    ) {}

    /**
     * Check availability of a specific 9-digit line (subPrefix + 6-digit suffix)
     * and produce smart alternative suggestions if already occupied.
     */
    public function checkAndSuggest(
        int $prefixId,
        string $subPrefix,
        string $suffix,
        ?User $user = null
    ): array {
        /** @var PhonePrefix $prefix */
        $prefix = PhonePrefix::findOrFail($prefixId);

        $cleanSub = preg_replace('/\D/', '', $subPrefix);
        $cleanSuffix = preg_replace('/\D/', '', $suffix);

        $fullNumber = $cleanSub . $cleanSuffix;

        // Check in database
        $existing = PhoneNumber::where('phone_prefix_id', $prefix->id)
            ->where('number', $fullNumber)
            ->first();

        $isAvailable = ($existing === null) || ($existing->status === PhoneNumberStatus::Available);

        $rond = $this->rondDetector->detect($fullNumber, $cleanSuffix);
        $price = $prefix->getPriceForTier($rond['tier']);

        if ($isAvailable) {
            return [
                'is_available' => true,
                'number' => $fullNumber,
                'sub_prefix' => $cleanSub,
                'suffix' => $cleanSuffix,
                'formatted' => "{$prefix->prefix} {$cleanSub} " . substr($cleanSuffix, 0, 3) . ' ' . substr($cleanSuffix, 3),
                'prefix_id' => $prefix->id,
                'country_name' => $prefix->country_name ?? $prefix->name,
                'country_code' => $prefix->country_code,
                'flag' => $prefix->flag ?? '🌐',
                'rond' => $rond,
                'price' => $price,
                'suggestions' => [],
            ];
        }

        // The number is occupied. Generate smart alternatives!
        $suggestions = [];

        // Priority 1: Same 6-digit suffix with all other configured sub-prefixes
        $allSubPrefixes = $prefix->getSubPrefixesList();
        foreach ($allSubPrefixes as $otherSub) {
            if ($otherSub === $cleanSub) {
                continue;
            }

            $candidate = $otherSub . $cleanSuffix;
            $candidateExists = PhoneNumber::where('phone_prefix_id', $prefix->id)
                ->where('number', $candidate)
                ->whereIn('status', [PhoneNumberStatus::Allocated->value, PhoneNumberStatus::Reserved->value])
                ->exists();

            if (! $candidateExists) {
                $candRond = $this->rondDetector->detect($candidate, $cleanSuffix);
                $candPrice = $prefix->getPriceForTier($candRond['tier']);

                $suggestions[] = [
                    'priority' => 1,
                    'type' => 'same_suffix',
                    'badge' => "همین ۶ رقم با پیش‌کد {$otherSub}",
                    'number' => $candidate,
                    'sub_prefix' => $otherSub,
                    'suffix' => $cleanSuffix,
                    'formatted' => "{$prefix->prefix} {$otherSub} " . substr($cleanSuffix, 0, 3) . ' ' . substr($cleanSuffix, 3),
                    'rond' => $candRond,
                    'price' => $candPrice,
                ];
            }
        }

        // Priority 2: Similar free numbers from available pool or generated
        $poolNumbers = PhoneNumber::where('phone_prefix_id', $prefix->id)
            ->where('status', PhoneNumberStatus::Available)
            ->where('type', PhoneNumberType::UserStandard)
            ->limit(30)
            ->get();

        $scoredPool = [];
        foreach ($poolNumbers as $poolPhone) {
            $num = $poolPhone->number;
            if (strlen($num) !== 9) {
                continue;
            }

            // Calculate similarity score based on matching characters
            similar_text($fullNumber, $num, $percent);
            $scoredPool[] = [
                'score' => $percent,
                'phone' => $poolPhone,
            ];
        }

        usort($scoredPool, fn ($a, $b) => $b['score'] <=> $a['score']);

        foreach (array_slice($scoredPool, 0, 4) as $item) {
            $pNum = $item['phone']->number;
            $pSub = substr($pNum, 0, 3);
            $pSuf = substr($pNum, 3);
            $pRond = $this->rondDetector->detect($pNum, $pSuf);
            $pPrice = $prefix->getPriceForTier($pRond['tier']);

            $suggestions[] = [
                'priority' => 2,
                'type' => 'pool_similar',
                'badge' => 'شماره آزاد با بیشترین شباهت',
                'number' => $pNum,
                'sub_prefix' => $pSub,
                'suffix' => $pSuf,
                'formatted' => "{$prefix->prefix} {$pSub} " . substr($pSuf, 0, 3) . ' ' . substr($pSuf, 3),
                'rond' => $pRond,
                'price' => $pPrice,
            ];
        }

        // If still fewer than 4 suggestions, generate fresh available candidates with same subPrefix
        if (count($suggestions) < 4) {
            $attempts = 0;
            while (count($suggestions) < 4 && $attempts < 15) {
                $attempts++;
                // Generate variation by changing 1 or 2 digits
                $variationSuffix = substr_replace($cleanSuffix, (string) random_int(0, 9), random_int(0, 5), 1);
                $varNumber = $cleanSub . $variationSuffix;

                $occupied = PhoneNumber::where('phone_prefix_id', $prefix->id)
                    ->where('number', $varNumber)
                    ->whereIn('status', [PhoneNumberStatus::Allocated->value, PhoneNumberStatus::Reserved->value])
                    ->exists();

                if (! $occupied) {
                    $varRond = $this->rondDetector->detect($varNumber, $variationSuffix);
                    $varPrice = $prefix->getPriceForTier($varRond['tier']);

                    $suggestions[] = [
                        'priority' => 2,
                        'type' => 'generated_alternative',
                        'badge' => "شماره آزاد جایگزین با پیش‌کد {$cleanSub}",
                        'number' => $varNumber,
                        'sub_prefix' => $cleanSub,
                        'suffix' => $variationSuffix,
                        'formatted' => "{$prefix->prefix} {$cleanSub} " . substr($variationSuffix, 0, 3) . ' ' . substr($variationSuffix, 3),
                        'rond' => $varRond,
                        'price' => $varPrice,
                    ];
                }
            }
        }

        return [
            'is_available' => false,
            'message' => "شماره {$fullNumber} در حال حاضر واگذار شده است.",
            'number' => $fullNumber,
            'sub_prefix' => $cleanSub,
            'suffix' => $cleanSuffix,
            'formatted' => "{$prefix->prefix} {$cleanSub} " . substr($cleanSuffix, 0, 3) . ' ' . substr($cleanSuffix, 3),
            'prefix_id' => $prefix->id,
            'country_name' => $prefix->country_name ?? $prefix->name,
            'country_code' => $prefix->country_code,
            'flag' => $prefix->flag ?? '🌐',
            'rond' => $rond,
            'price' => $price,
            'suggestions' => array_values($suggestions),
        ];
    }

    /**
     * Get free numbers from available pool or generated candidates for browsing.
     */
    public function getPoolSuggestions(int $prefixId, ?string $subPrefix = null, int $limit = 6): array
    {
        /** @var PhonePrefix $prefix */
        $prefix = PhonePrefix::findOrFail($prefixId);
        $sub = $subPrefix ?: ($prefix->getSubPrefixesList()[0] ?? '912');

        $query = PhoneNumber::where('phone_prefix_id', $prefix->id)
            ->where('status', PhoneNumberStatus::Available)
            ->where('type', PhoneNumberType::UserStandard);

        if ($subPrefix) {
            $query->where('number', 'like', "{$sub}%");
        }

        $list = $query->limit($limit)->get();
        $results = [];

        foreach ($list as $phone) {
            $num = $phone->number;
            $suf = substr($num, 3);
            $pSub = substr($num, 0, 3);
            $rond = $this->rondDetector->detect($num, $suf);
            $price = $prefix->getPriceForTier($rond['tier']);

            $results[] = [
                'number' => $num,
                'sub_prefix' => $pSub,
                'suffix' => $suf,
                'formatted' => "{$prefix->prefix} {$pSub} " . substr($suf, 0, 3) . ' ' . substr($suf, 3),
                'rond' => $rond,
                'price' => $price,
            ];
        }

        // If pool has fewer than limit, create candidate suggestions
        $attempts = 0;
        while (count($results) < $limit && $attempts < 20) {
            $attempts++;
            $randSuffix = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $candidateNum = $sub . $randSuffix;

            $occupied = PhoneNumber::where('phone_prefix_id', $prefix->id)
                ->where('number', $candidateNum)
                ->exists();

            if (! $occupied) {
                $rond = $this->rondDetector->detect($candidateNum, $randSuffix);
                $price = $prefix->getPriceForTier($rond['tier']);

                $results[] = [
                    'number' => $candidateNum,
                    'sub_prefix' => $sub,
                    'suffix' => $randSuffix,
                    'formatted' => "{$prefix->prefix} {$sub} " . substr($randSuffix, 0, 3) . ' ' . substr($randSuffix, 3),
                    'rond' => $rond,
                    'price' => $price,
                ];
            }
        }

        return $results;
    }
}

