<?php

return [
    'audit_log' => [
        'actions' => [
            'user_created' => 'User account created',
            'user_updated' => 'User account updated',
            'user_status_changed' => 'User status changed',
            'user_role_changed' => 'User role changed',
            'number_created' => 'New number generated/created',
            'number_assigned' => 'Number allocated to user',
            'number_released' => 'Number released',
            'number_status_changed' => 'Number status changed',
            'request_submitted' => 'Number request submitted',
            'request_approved' => 'Number request approved',
            'request_rejected' => 'Number request rejected',
            'token_created' => 'API token created',
            'token_rotated' => 'API token rotated',
            'token_revoked' => 'API token revoked',
            'message_deleted' => 'Message deleted from system',
            'message_sent' => 'New message sent',
            'number_updated' => 'Number updated',
            'number_deleted' => 'Number deleted',
            'ticket_created' => 'Support ticket created',
            'ticket_replied' => 'Ticket reply posted',
            'ticket_status_changed' => 'Ticket status changed',
            'ticket_assigned' => 'Ticket assigned to agent',
            'wallet_charged_usage' => 'Wallet charged for service usage',
            'order_approved' => 'Service order approved',
            'order_rejected' => 'Service order rejected',
            'order_referred' => 'Service order referred for review',
            'prefix_tariffs_updated' => 'Prefix tariffs updated',
            'global_tariffs_updated' => 'Global tariffs updated',
            'service_purchasability_toggled' => 'Service purchasability toggled',
            'service_activation_toggled' => 'Service activation toggled',
            'service_approval_mode_updated' => 'Service approval policy updated',
            'id_pricing_updated' => 'Custom ID pricing updated',
        ],
    ],
    'phone_number' => [
        'rond_tiers' => [
            'golden_rond' => 'Golden Rond',
            'semi_rond' => 'Semi-Rond',
            'regular' => 'Regular',
        ],
        'inbound_rules' => [
            'not_allocated' => 'The destination line is in status ":status" and cannot receive SMS.',
            'incoming_disabled' => 'This number is configured as one-way outbound and does not accept inbound SMS.',
            'platform_service_forbidden' => 'Sending SMS to this platform service number by standard users is not permitted.',
        ],
    ],
    'phone_prefix' => [
        'default_name' => 'Default Organizational Prefix',
    ],
    'ticket_attachment' => [
        'units' => [
            'mb' => ':size MB',
            'kb' => ':size KB',
            'bytes' => ':size Bytes',
        ],
    ],
    'user_identity' => [
        'providers' => [
            'username' => 'Username & Password',
            'email' => 'Email Address',
            'ldap' => 'Corporate LDAP / Active Directory',
            'keycloak' => 'Keycloak Single Sign-On',
        ],
    ],
];