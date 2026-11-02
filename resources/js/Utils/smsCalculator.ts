/**
 * SMS Segment & Character Encoding Calculator.
 * Accurately calculates GSM-7 vs UCS-2 segment counts, character quotas, and cost estimates.
 */

// GSM 03.38 Basic Character Set
const GSM_BASIC_CHARS = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ\x1bÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

// GSM 03.38 Extension Characters (Cost 2 septets / units each)
const GSM_EXTENSION_CHARS = "^{}\\[~]|€";

export interface SmsCalculationResult {
    encoding: 'GSM-7' | 'UCS-2';
    charCount: number;
    unitsUsed: number;
    segmentCount: number;
    maxPerSegment: number;
    charsRemainingInSegment: number;
    ratePerSegment: string;
    currency: string;
    estimatedCost: string;
    recipientCount: number;
    totalCost: string;
}

/**
 * Calculate segments, encoding, and estimated coin cost.
 */
export function calculateSmsSegments(
    text: string,
    ratePerSegment = '0.0500',
    recipientCount = 1,
    currency = 'COIN'
): SmsCalculationResult {
    const safeRecipients = Math.max(1, recipientCount);
    const rateNum = parseFloat(ratePerSegment) || 0.05;

    if (!text || text.length === 0) {
        return {
            encoding: 'GSM-7',
            charCount: 0,
            unitsUsed: 0,
            segmentCount: 0,
            maxPerSegment: 160,
            charsRemainingInSegment: 160,
            ratePerSegment,
            currency,
            estimatedCost: '0.0000',
            recipientCount: safeRecipients,
            totalCost: '0.0000',
        };
    }

    let isGsm = true;
    let unitsUsed = 0;
    const charArray = Array.from(text); // Correctly handles multi-byte surrogate pairs/emojis
    const charCount = charArray.length;

    for (let i = 0; i < charCount; i++) {
        const char = charArray[i];
        if (GSM_BASIC_CHARS.includes(char)) {
            unitsUsed += 1;
        } else if (GSM_EXTENSION_CHARS.includes(char)) {
            unitsUsed += 2;
        } else {
            isGsm = false;
            break;
        }
    }

    let encoding: 'GSM-7' | 'UCS-2';
    let segmentCount = 1;
    let maxPerSegment: number;
    let charsRemaining: number;

    if (isGsm) {
        encoding = 'GSM-7';
        if (unitsUsed <= 160) {
            segmentCount = 1;
            maxPerSegment = 160;
            charsRemaining = 160 - unitsUsed;
        } else {
            maxPerSegment = 153;
            segmentCount = Math.ceil(unitsUsed / 153);
            charsRemaining = segmentCount * 153 - unitsUsed;
        }
    } else {
        encoding = 'UCS-2';
        unitsUsed = charCount;
        if (unitsUsed <= 70) {
            segmentCount = 1;
            maxPerSegment = 70;
            charsRemaining = 70 - unitsUsed;
        } else {
            maxPerSegment = 67;
            segmentCount = Math.ceil(unitsUsed / 67);
            charsRemaining = segmentCount * 67 - unitsUsed;
        }
    }

    const singleCostNum = rateNum * segmentCount;
    const totalCostNum = singleCostNum * safeRecipients;

    return {
        encoding,
        charCount,
        unitsUsed,
        segmentCount,
        maxPerSegment,
        charsRemainingInSegment: Math.max(0, charsRemaining),
        ratePerSegment,
        currency,
        estimatedCost: singleCostNum.toFixed(4),
        recipientCount: safeRecipients,
        totalCost: totalCostNum.toFixed(4),
    };
}

