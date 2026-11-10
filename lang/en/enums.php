<?php

return [
    'api_token_status' => [
        'active' => 'Active & Valid',
        'revoked' => 'Revoked',
    ],
    'crypto_network' => [
        'TRON' => 'Tron Network (TRON / TRX)',
        'TRC20' => 'Tron Network (TRC-20)',
        'ERC20' => 'Ethereum Network (ERC-20)',
        'BEP20' => 'Binance Smart Chain (BEP-20)',
    ],
    'message_direction' => [
        'inbound' => 'Inbound',
        'outbound' => 'Outbound',
    ],
    'message_processing_status' => [
        'received' => 'Received',
        'processed' => 'Processed',
        'failed' => 'Processing Failed',
    ],
    'number_request_status' => [
        'pending' => 'Pending Review',
        'approved' => 'Approved & Allocated',
        'rejected' => 'Rejected',
    ],
    'payment_status' => [
        'pending_transfer' => 'Pending TRX Transfer',
        'tx_submitted' => 'Transaction Hash Submitted',
        'verifying' => 'Verifying with Network',
        'manual_review' => 'Manual Review Required',
        'completed' => 'Approved & Credited',
        'failed' => 'Failed / Rejected',
        'expired' => 'Expired',
    ],
    'phone_number_status' => [
        'available' => 'Available for Allocation',
        'allocated' => 'Allocated',
        'reserved' => 'Reserved',
        'suspended' => 'Suspended',
        'inactive' => 'Inactive',
        'expired' => 'Expired',
    ],
    'phone_number_type' => [
        'user_standard' => 'Standard',
        'user_service' => 'Business Service',
        'platform_service' => 'Platform Service',
    ],
    'ticket_department' => [
        'technical' => 'Technical Support',
        'numbers' => 'Special Number Allocation',
        'api' => 'Web Service & API',
        'general' => 'General & Billing',
    ],
    'ticket_priority' => [
        'low' => 'Low',
        'medium' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],
    'ticket_status' => [
        'open' => 'Awaiting Agent Response',
        'in_progress' => 'In Progress',
        'answered' => 'Answered',
        'closed' => 'Closed',
    ],
    'user_role' => [
        'admin' => 'Super Administrator',
        'agent' => 'Support Agent',
        'user' => 'Business User',
    ],
    'user_status' => [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'inactive' => 'Inactive',
    ],
    'wallet_transaction_type' => [
        'DEPOSIT' => 'Account Deposit',
        'NUMBER_PURCHASE' => 'Virtual Number Purchase',
        'HOLD' => 'Funds Hold (Service Number Request)',
        'HOLD_RELEASE' => 'Hold Release (Rejected/Cancelled)',
        'ADMIN_CREDIT' => 'Credit Added by Admin',
        'ADMIN_DEBIT' => 'Debit Deducted by Admin',
        'REFUND' => 'Refund',
        'REVERSAL' => 'Adjustment Reversal',
    ],
];
