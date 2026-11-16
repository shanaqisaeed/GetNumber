<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'فیلد :attribute باید پذیرفته شود.',
    'accepted_if' => 'وقتی :other برابر با :value است، فیلد :attribute باید پذیرفته شود.',
    'active_url' => 'فیلد :attribute باید یک نشانی اینترنتی معتبر باشد.',
    'after' => 'فیلد :attribute باید تاریخی پس از :date باشد.',
    'after_or_equal' => 'فیلد :attribute باید تاریخی برابر یا پس از :date باشد.',
    'alpha' => 'فیلد :attribute فقط می‌تواند شامل حروف باشد.',
    'alpha_dash' => 'فیلد :attribute فقط می‌تواند شامل حروف، ارقام، خط تیره و زیرخط باشد.',
    'alpha_num' => 'فیلد :attribute فقط می‌تواند شامل حروف و ارقام باشد.',
    'any_of' => 'فیلد :attribute نامعتبر است.',
    'array' => 'فیلد :attribute باید به صورت آرایه باشد.',
    'array_keys' => 'فیلد :attribute فقط می‌تواند شامل کلیدهای زیر باشد: :values.',
    'ascii' => 'فیلد :attribute فقط می‌تواند شامل کاراکترهای الفبایی-عددی و نمادهای تک‌بایتی باشد.',
    'base64' => 'فیلد :attribute باید یک رشته معتبر Base64 باشد.',
    'before' => 'فیلد :attribute باید تاریخی پیش از :date باشد.',
    'before_or_equal' => 'فیلد :attribute باید تاریخی برابر یا پیش از :date باشد.',
    'between' => [
        'array' => 'فیلد :attribute باید بین :min تا :max آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید بین :min تا :max کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید بین :min تا :max باشد.',
        'string' => 'طول رشته :attribute باید بین :min تا :max کاراکتر باشد.',
    ],
    'boolean' => 'مقدار فیلد :attribute فقط می‌تواند صحیح یا غلط باشد.',
    'can' => 'فیلد :attribute شامل مقدار غیرمجاز است.',
    'confirmed' => 'تأییدیه فیلد :attribute با مقدار آن مطابقت ندارد.',
    'contains' => 'فیلد :attribute فاقد یک مقدار الزامی است.',
    'current_password' => 'گذرواژه وارد شده نادرست است.',
    'date' => 'فیلد :attribute باید یک تاریخ معتبر باشد.',
    'date_equals' => 'فیلد :attribute باید تاریخی برابر با :date باشد.',
    'date_format' => 'فیلد :attribute باید با قالب :format مطابقت داشته باشد.',
    'decimal' => 'فیلد :attribute باید دقیقاً :decimal رقم اعشار داشته باشد.',
    'declined' => 'فیلد :attribute باید رد شود.',
    'declined_if' => 'وقتی :other برابر با :value است، فیلد :attribute باید رد شود.',
    'different' => 'فیلدهای :attribute و :other باید متفاوت باشند.',
    'digits' => 'فیلد :attribute باید دقیقاً :digits رقم باشد.',
    'digits_between' => 'فیلد :attribute باید بین :min تا :max رقم باشد.',
    'dimensions' => 'ابعاد تصویر :attribute نامعتبر است.',
    'distinct' => 'فیلد :attribute دارای مقدار تکراری است.',
    'doesnt_contain' => 'فیلد :attribute نباید شامل هیچ‌یک از موارد زیر باشد: :values.',
    'doesnt_end_with' => 'فیلد :attribute نباید با هیچ‌یک از موارد زیر پایان یابد: :values.',
    'doesnt_start_with' => 'فیلد :attribute نباید با هیچ‌یک از موارد زیر آغاز شود: :values.',
    'email' => 'فیلد :attribute باید یک نشانی ایمیل معتبر باشد.',
    'encoding' => 'فیلد :attribute باید با کدگذاری :encoding باشد.',
    'ends_with' => 'فیلد :attribute باید با یکی از مقادیر زیر پایان یابد: :values.',
    'enum' => 'مقدار انتخاب‌شده برای :attribute نامعتبر است.',
    'exists' => 'مقدار انتخاب‌شده برای :attribute نامعتبر است.',
    'extensions' => 'فیلد :attribute باید دارای یکی از پسوندهای زیر باشد: :values.',
    'file' => 'فیلد :attribute باید یک فایل باشد.',
    'filled' => 'فیلد :attribute نباید خالی باشد.',
    'gt' => [
        'array' => 'فیلد :attribute باید بیش از :value آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید بیشتر از :value کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید بزرگتر از :value باشد.',
        'string' => 'طول رشته :attribute باید بیشتر از :value کاراکتر باشد.',
    ],
    'gte' => [
        'array' => 'فیلد :attribute باید حداقل :value آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید بزرگتر یا مساوی :value کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید بزرگتر یا مساوی :value باشد.',
        'string' => 'طول رشته :attribute باید بزرگتر یا مساوی :value کاراکتر باشد.',
    ],
    'hex_color' => 'فیلد :attribute باید یک کد رنگ هگزادسیمال معتبر باشد.',
    'image' => 'فیلد :attribute باید یک تصویر باشد.',
    'in' => 'مقدار انتخاب‌شده برای :attribute نامعتبر است.',
    'in_array' => 'فیلد :attribute باید در :other وجود داشته باشد.',
    'in_array_keys' => 'فیلد :attribute باید حداقل شامل یکی از کلیدهای زیر باشد: :values.',
    'integer' => 'فیلد :attribute باید یک عدد صحیح باشد.',
    'ip' => 'فیلد :attribute باید یک نشانی IP معتبر باشد.',
    'ipv4' => 'فیلد :attribute باید یک نشانی IPv4 معتبر باشد.',
    'ipv6' => 'فیلد :attribute باید یک نشانی IPv6 معتبر باشد.',
    'json' => 'فیلد :attribute باید یک رشته JSON معتبر باشد.',
    'list' => 'فیلد :attribute باید به صورت فهرست (List) باشد.',
    'lowercase' => 'فیلد :attribute باید با حروف کوچک باشد.',
    'lt' => [
        'array' => 'فیلد :attribute باید کمتر از :value آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید کمتر از :value کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید کمتر از :value باشد.',
        'string' => 'طول رشته :attribute باید کمتر از :value کاراکتر باشد.',
    ],
    'lte' => [
        'array' => 'فیلد :attribute نباید بیش از :value آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید کمتر یا مساوی :value کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید کمتر یا مساوی :value باشد.',
        'string' => 'طول رشته :attribute باید کمتر یا مساوی :value کاراکتر باشد.',
    ],
    'mac_address' => 'فیلد :attribute باید یک نشانی MAC معتبر باشد.',
    'max' => [
        'array' => 'فیلد :attribute نباید بیش از :max آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute نباید بیشتر از :max کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute نباید بزرگتر از :max باشد.',
        'string' => 'طول رشته :attribute نباید بیشتر از :max کاراکتر باشد.',
    ],
    'max_digits' => 'فیلد :attribute نباید بیش از :max رقم داشته باشد.',
    'mimes' => 'فیلد :attribute باید فایلی از نوع زیر باشد: :values.',
    'mimetypes' => 'فیلد :attribute باید فایلی از نوع زیر باشد: :values.',
    'min' => [
        'array' => 'فیلد :attribute باید حداقل :min آیتم داشته باشد.',
        'file' => 'حجم فایل :attribute باید حداقل :min کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید حداقل :min باشد.',
        'string' => 'طول رشته :attribute باید حداقل :min کاراکتر باشد.',
    ],
    'min_digits' => 'فیلد :attribute باید حداقل :min رقم داشته باشد.',
    'missing' => 'فیلد :attribute نباید ارسال شود.',
    'missing_if' => 'وقتی :other برابر با :value است، فیلد :attribute نباید وجود داشته باشد.',
    'missing_unless' => 'فیلد :attribute نباید وجود داشته باشد مگر اینکه :other برابر با :value باشد.',
    'missing_with' => 'وقتی :values وجود دارد، فیلد :attribute نباید ارسال شود.',
    'missing_with_all' => 'وقتی :values وجود دارند، فیلد :attribute نباید ارسال شود.',
    'multiple_of' => 'فیلد :attribute باید مضربی از :value باشد.',
    'not_in' => 'مقدار انتخاب‌شده برای :attribute نامعتبر است.',
    'not_regex' => 'قالب فیلد :attribute نامعتبر است.',
    'numeric' => 'فیلد :attribute باید یک عدد باشد.',
    'password' => [
        'letters' => 'فیلد :attribute باید حداقل شامل یک حرف باشد.',
        'mixed' => 'فیلد :attribute باید حداقل شامل یک حرف بزرگ و یک حرف کوچک باشد.',
        'numbers' => 'فیلد :attribute باید حداقل شامل یک عدد باشد.',
        'symbols' => 'فیلد :attribute باید حداقل شامل یک نماد باشد.',
        'uncompromised' => 'مقدار :attribute وارد شده در نشت اطلاعات قبلی دیده شده است. لطفاً مقدار دیگری انتخاب کنید.',
    ],
    'present' => 'فیلد :attribute باید در درخواست وجود داشته باشد.',
    'present_if' => 'وقتی :other برابر با :value است، فیلد :attribute باید وجود داشته باشد.',
    'present_unless' => 'فیلد :attribute باید وجود داشته باشد مگر اینکه :other برابر با :value باشد.',
    'present_with' => 'وقتی :values وجود دارد، فیلد :attribute باید وجود داشته باشد.',
    'present_with_all' => 'وقتی :values وجود دارند، فیلد :attribute باید وجود داشته باشد.',
    'prohibited' => 'ارسال فیلد :attribute مجاز نمی‌باشد.',
    'prohibited_if' => 'وقتی :other برابر با :value است، ارسال فیلد :attribute مجاز نمی‌باشد.',
    'prohibited_if_accepted' => 'وقتی :other پذیرفته شده است، ارسال فیلد :attribute مجاز نمی‌باشد.',
    'prohibited_if_declined' => 'وقتی :other رد شده است، ارسال فیلد :attribute مجاز نمی‌باشد.',
    'prohibited_unless' => 'ارسال فیلد :attribute مجاز نیست مگر اینکه :other در :values باشد.',
    'prohibits' => 'وجود فیلد :attribute مانع از ارسال :other می‌شود.',
    'regex' => 'قالب فیلد :attribute نامعتبر است.',
    'required' => 'تکمیل فیلد :attribute الزامی است.',
    'required_array_keys' => 'فیلد :attribute باید شامل کلیدهای زیر باشد: :values.',
    'required_if' => 'وقتی :other برابر با :value است، فیلد :attribute الزامی است.',
    'required_if_accepted' => 'وقتی :other پذیرفته شده است، فیلد :attribute الزامی است.',
    'required_if_declined' => 'وقتی :other رد شده است، فیلد :attribute الزامی است.',
    'required_unless' => 'فیلد :attribute الزامی است مگر اینکه :other در :values باشد.',
    'required_with' => 'وقتی :values وجود دارد، فیلد :attribute الزامی است.',
    'required_with_all' => 'وقتی :values وجود دارند، فیلد :attribute الزامی است.',
    'required_without' => 'وقتی :values وجود ندارد، فیلد :attribute الزامی است.',
    'required_without_all' => 'وقتی هیچ‌یک از :values وجود ندارند، فیلد :attribute الزامی است.',
    'same' => 'فیلد :attribute باید با :other مطابقت داشته باشد.',
    'size' => [
        'array' => 'فیلد :attribute باید شامل :size آیتم باشد.',
        'file' => 'حجم فایل :attribute باید دقیقاً :size کیلوبایت باشد.',
        'numeric' => 'مقدار :attribute باید دقیقاً :size باشد.',
        'string' => 'طول رشته :attribute باید دقیقاً :size کاراکتر باشد.',
    ],
    'starts_with' => 'فیلد :attribute باید با یکی از مقادیر زیر آغاز شود: :values.',
    'string' => 'فیلد :attribute باید یک رشته متنی باشد.',
    'timezone' => 'فیلد :attribute باید یک منطقه زمانی معتبر باشد.',
    'unique' => 'مقدار :attribute قبلاً ثبت شده است.',
    'uploaded' => 'بارگذاری فایل :attribute با خطا مواجه شد.',
    'uppercase' => 'فیلد :attribute باید با حروف بزرگ باشد.',
    'url' => 'فیلد :attribute باید یک نشانی اینترنتی معتبر باشد.',
    'ulid' => 'فیلد :attribute باید یک ULID معتبر باشد.',
    'uuid' => 'فیلد :attribute باید یک UUID معتبر باشد.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

];

