<?php

return [
    'profile' => [
        'name_required' => 'Organization title / name is required.',
        'name_max' => 'Organization title / name cannot exceed 255 characters.',
    ],
    'admin' => [
        'approve_number_request' => [
            'mode_required' => 'Allocation mode is required.',
            'mode_in' => 'The selected allocation mode is invalid.',
            'custom_number_required' => 'Custom number is required in manual allocation mode.',
            'custom_number_unique' => 'This number is already registered in the system and unavailable.',
            'custom_number_digits' => 'The custom number length must be exactly :length digits.',
            'custom_number_digits_invalid' => 'The custom number length is invalid.',
            'custom_number_regex' => 'The custom number must contain 4 to 8 digits only.',
            'pool_number_required' => 'Selecting a number from the pool is required.',
            'pool_number_exists' => 'The selected number does not exist in the system pool.',
        ],
        'reject_number_request' => [
            'reason_required' => 'A rejection reason is required to notify the applicant.',
            'reason_min' => 'The rejection reason must be at least 3 characters.',
            'reason_max' => 'The rejection reason cannot exceed 500 characters.',
        ],
    ],
    'auth' => [
        'login' => [
            'username_required' => 'Username is required.',
            'email_required' => 'Email address is required.',
            'email_invalid' => 'The email format is invalid.',
            'password_required' => 'Password is required.',
            'ldap_username_required' => 'LDAP corporate username is required.',
            'ldap_password_required' => 'LDAP corporate password is required.',
            'provider_disabled' => 'Authentication via this provider is currently disabled by system administration.',
            'throttle' => 'Too many failed login attempts. Please try again in :seconds seconds.',
        ],
    ],
    'user' => [
        'store_number_request' => [
            'requested_length_required' => 'Organizational number length is required.',
            'requested_length_between' => 'The requested number length must be between 4 and 8 digits.',
            'service_title_required' => 'Service title is required.',
            'service_title_min' => 'Service title must be at least 2 characters.',
            'service_title_max' => 'Service title cannot exceed 100 characters.',
            'description_required' => 'Description of organizational usage is required.',
            'description_min' => 'Description must be at least 5 characters.',
            'description_max' => 'Description cannot exceed 1000 characters.',
            'preferred_number_regex' => 'The preferred number must contain 4 to 8 digits only.',
            'preferred_number_length_mismatch' => 'The preferred number length (:actual digits) does not match the requested length (:expected digits).',
        ],
        'allocate_phone_number' => [
            'phone_prefix_id_exists' => 'The selected phone prefix is invalid.',
            'preferred_number_regex' => 'The requested phone number format is invalid.',
            'display_title_max' => 'Display title cannot exceed 100 characters.',
        ],
    ],
];