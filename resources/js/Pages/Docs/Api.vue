<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    baseUrl: string;
    defaultPrefix: string;
    activeCleanPrefixes: string[];
    mockToken: string;
    mockDestination: string;
}>();

// Active tab for code examples
type LanguageKey = 'curl' | 'python' | 'php' | 'javascript' | 'typescript' | 'nodejs' | 'java' | 'csharp' | 'go' | 'ruby' | 'powershell';
const selectedLang = ref<LanguageKey>('curl');

const languages: { key: LanguageKey; name: string; icon: string }[] = [
    { key: 'curl', name: 'cURL', icon: '⚡' },
    { key: 'python', name: 'Python', icon: '🐍' },
    { key: 'php', name: 'PHP', icon: '🐘' },
    { key: 'javascript', name: 'JavaScript', icon: '🌐' },
    { key: 'typescript', name: 'TypeScript', icon: '📘' },
    { key: 'nodejs', name: 'Node.js', icon: '🟢' },
    { key: 'java', name: 'Java', icon: '☕' },
    { key: 'csharp', name: 'C# (.NET)', icon: '🔷' },
    { key: 'go', name: 'Go', icon: '🐹' },
    { key: 'ruby', name: 'Ruby', icon: '💎' },
    { key: 'powershell', name: 'PowerShell', icon: '💻' },
];

// Active tab for endpoints
const activeEndpoint = ref<'store' | 'list' | 'poll' | 'numbers' | 'ping'>('store');

// Copy feedback
const copiedKey = ref<string | null>(null);
const copyCode = async (code: string, key: string) => {
    try {
        await navigator.clipboard.writeText(code);
        copiedKey.value = key;
        setTimeout(() => {
            if (copiedKey.value === key) copiedKey.value = null;
        }, 2500);
    } catch {
        // Fallback
    }
};

const codeSnippets = computed<Record<LanguageKey, string>>(() => ({
    curl: `curl -X POST "${props.baseUrl}/api/v1/messages" \\
  -H "Authorization: Bearer ${props.mockToken}" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json" \\
  -d '{
    "destination_number": "${props.mockDestination}",
    "sender": "+989121111111",
    "body": "کد تأیید ورود شما: ۱۲۳۴۵",
    "client_message_id": "MSG-9876543210",
    "source_system_id": "GATEWAY-TEHRAN"
  }'`,

    python: `import requests
import json

url = "${props.baseUrl}/api/v1/messages"
token = "${props.mockToken}"

headers = {
    "Authorization": f"Bearer {token}",
    "Content-Type": "application/json",
    "Accept": "application/json"
}

payload = {
    "destination_number": "${props.mockDestination}",
    "sender": "+989121111111",
    "body": "کد تأیید ورود شما: ۱۲۳۴۵",
    "client_message_id": "MSG-9876543210",
    "source_system_id": "GATEWAY-TEHRAN"
}

try:
    response = requests.post(url, json=payload, headers=headers, timeout=10)
    data = response.json()

    if response.status_code == 201:
        print("پیام جدید با موفقیت در سامانه ثبت شد. شناسه:", data["data"]["id"])
    elif response.status_code == 200 and data.get("data", {}).get("is_duplicate"):
        print("درخواست تکراری معتبر (Idempotent):", data["message"])
    else:
        print(f"خطای وب‌سرویس ({response.status_code}):", data.get("message"))
except requests.exceptions.RequestException as e:
    print("خطای ارتباط با سرور:", str(e))`,

    php: `<?php

$url = '${props.baseUrl}/api/v1/messages';
$token = '${props.mockToken}';

$payload = [
    'destination_number' => '${props.mockDestination}',
    'sender' => '+989121111111',
    'body' => 'کد تأیید ورود شما: ۱۲۳۴۵',
    'client_message_id' => 'MSG-9876543210',
    'source_system_id' => 'GATEWAY-TEHRAN'
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$token}",
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "خطای ارتباط: {$error}\n";
    exit;
}

$data = json_decode($response, true);
if ($httpCode === 201) {
    echo "پیام با موفقیت ثبت شد. شناسه: " . $data['data']['id'] . "\n";
} elseif ($httpCode === 200 && ($data['data']['is_duplicate'] ?? false)) {
    echo "پیام تکراری قبلاً ثبت شده: " . $data['message'] . "\n";
} else {
    echo "خطا ({$httpCode}): " . ($data['message'] ?? 'خطای اعتبارسنجی یا سرور') . "\n";
}`,

    javascript: `const apiUrl = '${props.baseUrl}/api/v1/messages';
const apiToken = '${props.mockToken}';

async function recordInboundMessage() {
  const payload = {
    destination_number: '${props.mockDestination}',
    sender: '+989121111111',
    body: 'کد تأیید ورود شما: ۱۲۳۴۵',
    client_message_id: 'MSG-9876543210',
    source_system_id: 'GATEWAY-TEHRAN'
  };

  try {
    const res = await fetch(apiUrl, {
      method: 'POST',
      headers: {
        'Authorization': \`Bearer \${apiToken}\`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    const data = await res.json();

    if (res.status === 201) {
      console.log('پیام با موفقیت در سامانه ثبت شد:', data.data);
    } else if (res.status === 200 && data.data?.is_duplicate) {
      console.warn('درخواست تکراری معتبر (Idempotent):', data.message);
    } else {
      console.error(\`خطا (\${res.status}):\`, data.message, data.errors || '');
    }
  } catch (err) {
    console.error('عدم برقراری ارتباط با وب‌سرویس:', err);
  }
}

recordInboundMessage();`,

    typescript: `interface MessagePayload {
  destination_number: string;
  sender: string;
  body: string;
  client_message_id?: string;
  source_system_id?: string;
  source_time?: string;
}

interface ApiResponse<T> {
  success: boolean;
  message: string;
  code?: string;
  data?: T;
  errors?: Record<string, string[]>;
}

interface RecordedMessage {
  id: number;
  destination_number: string;
  destination_title: string;
  sender: string;
  body: string;
  is_duplicate: boolean;
}

const API_URL = '${props.baseUrl}/api/v1/messages';
const API_TOKEN = '${props.mockToken}';

async function sendInboundMessage(payload: MessagePayload): Promise<void> {
  try {
    const response = await fetch(API_URL, {
      method: 'POST',
      headers: {
        'Authorization': \`Bearer \${API_TOKEN}\`,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(payload),
    });

    const result: ApiResponse<RecordedMessage> = await response.json();

    if (response.status === 201) {
      console.log(\`پیام جدید با شناسه \${result.data?.id} با موفقیت ثبت شد.\`);
    } else if (response.status === 200 && result.data?.is_duplicate) {
      console.warn('پیام تکراری است (Idempotent):', result.message);
    } else {
      console.error(\`خطای سرور [\${result.code || response.status}]: \${result.message}\`);
    }
  } catch (error) {
    console.error('خطای ارتباط شبکه:', error);
  }
}

sendInboundMessage({
  destination_number: '${props.mockDestination}',
  sender: '+989121111111',
  body: 'کد تأیید ورود شما: ۱۲۳۴۵',
  client_message_id: 'MSG-9876543210',
  source_system_id: 'GATEWAY-TEHRAN',
});`,

    nodejs: `// نیازمند Node.js 18+ یا پکیج node-fetch
const API_URL = '${props.baseUrl}/api/v1/messages';
const TOKEN = '${props.mockToken}';

async function main() {
  const payload = {
    destination_number: '${props.mockDestination}',
    sender: '+989121111111',
    body: 'کد تأیید ورود شما: ۱۲۳۴۵',
    client_message_id: 'MSG-9876543210',
    source_system_id: 'GATEWAY-TEHRAN'
  };

  try {
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: {
        'Authorization': \`Bearer \${TOKEN}\`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(payload)
    });

    const body = await res.json();

    if (res.status === 201) {
      console.log('موفقیت‌آمیز - شناسه پیام در سامانه:', body.data.id);
    } else if (res.status === 200 && body.data?.is_duplicate) {
      console.log('پیام تکراری (قبلاً ثبت شده):', body.message);
    } else {
      console.error(\`خطای \${res.status}:\`, body.message, body.errors ?? '');
    }
  } catch (err) {
    console.error('خطای ارتباط شبکه:', err.message);
  }
}

main();`,

    java: `import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;

public class ApiClient {
    public static void main(String[] args) {
        String apiUrl = "${props.baseUrl}/api/v1/messages";
        String token = "${props.mockToken}";

        String jsonPayload = """
        {
            "destination_number": "${props.mockDestination}",
            "sender": "+989121111111",
            "body": "کد تأیید ورود شما: ۱۲۳۴۵",
            "client_message_id": "MSG-9876543210",
            "source_system_id": "GATEWAY-TEHRAN"
        }
        """;

        HttpClient client = HttpClient.newBuilder()
                .connectTimeout(Duration.ofSeconds(10))
                .build();

        HttpRequest request = HttpRequest.newBuilder()
                .uri(URI.create(apiUrl))
                .header("Authorization", "Bearer " + token)
                .header("Content-Type", "application/json")
                .header("Accept", "application/json")
                .POST(HttpRequest.BodyPublishers.ofString(jsonPayload))
                .build();

        try {
            HttpResponse<String> response = client.send(request, HttpResponse.BodyHandlers.ofString());
            int statusCode = response.statusCode();

            if (statusCode == 201) {
                System.out.println("پیام با موفقیت ثبت شد: " + response.body());
            } else if (statusCode == 200) {
                System.out.println("درخواست تکراری معتبر (Idempotent): " + response.body());
            } else {
                System.err.println("خطای وب‌سرویس (" + statusCode + "): " + response.body());
            }
        } catch (Exception e) {
            System.err.println("خطای برقراری ارتباط با سامانه: " + e.getMessage());
        }
    }
}`,

    csharp: `using System;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

class Program
{
    static async Task Main(string[] args)
    {
        var apiUrl = "${props.baseUrl}/api/v1/messages";
        var token = "${props.mockToken}";

        using var client = new HttpClient();
        client.DefaultRequestHeaders.Add("Authorization", $"Bearer {token}");
        client.DefaultRequestHeaders.Add("Accept", "application/json");

        var payload = new
        {
            destination_number = "${props.mockDestination}",
            sender = "+989121111111",
            body = "کد تأیید ورود شما: ۱۲۳۴۵",
            client_message_id = "MSG-9876543210",
            source_system_id = "GATEWAY-TEHRAN"
        };

        var content = new StringContent(JsonSerializer.Serialize(payload), Encoding.UTF8, "application/json");

        try
        {
            var response = await client.PostAsync(apiUrl, content);
            var responseBody = await response.Content.ReadAsStringAsync();

            if (response.StatusCode == System.Net.HttpStatusCode.Created)
            {
                Console.WriteLine($"پیام ورودی با موفقیت در سامانه ثبت گردید:\\n{responseBody}");
            }
            else if (response.StatusCode == System.Net.HttpStatusCode.OK)
            {
                Console.WriteLine($"درخواست تکراری معتبر (Idempotent):\\n{responseBody}");
            }
            else
            {
                Console.WriteLine($"خطای سرور [{(int)response.StatusCode}]:\\n{responseBody}");
            }
        }
        catch (HttpRequestException ex)
        {
            Console.WriteLine($"خطای ارتباط شبکه: {ex.Message}");
        }
    }
}`,

    go: `package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"
)

type MessageRequest struct {
	DestinationNumber string \`json:"destination_number"\`
	Sender            string \`json:"sender"\`
	Body              string \`json:"body"\`
	ClientMessageId   string \`json:"client_message_id"\`
	SourceSystemId    string \`json:"source_system_id"\`
}

func main() {
	apiUrl := "${props.baseUrl}/api/v1/messages"
	token := "${props.mockToken}"

	payload := MessageRequest{
		DestinationNumber: "${props.mockDestination}",
		Sender:            "+989121111111",
		Body:              "کد تأیید ورود شما: ۱۲۳۴۵",
		ClientMessageId:   "MSG-9876543210",
		SourceSystemId:    "GATEWAY-TEHRAN",
	}

	jsonData, err := json.Marshal(payload)
	if err != nil {
		fmt.Printf("خطا در سریال‌سازی داده: %v\\n", err)
		return
	}

	req, err := http.NewRequest("POST", apiUrl, bytes.NewBuffer(jsonData))
	if err != nil {
		fmt.Printf("خطا در ایجاد درخواست: %v\\n", err)
		return
	}

	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		fmt.Printf("خطای ارتباط با سرور: %v\\n", err)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode == http.StatusCreated {
		fmt.Printf("پیام با موفقیت ثبت شد: %s\\n", string(body))
	} else if resp.StatusCode == http.StatusOK {
		fmt.Printf("پیام تکراری معتبر (Idempotent): %s\\n", string(body))
	} else {
		fmt.Printf("خطای وب‌سرویس [%d]: %s\\n", resp.StatusCode, string(body))
	}
}`,

    ruby: `require 'net/http'
require 'uri'
require 'json'

uri = URI.parse('${props.baseUrl}/api/v1/messages')
token = '${props.mockToken}'

payload = {
  destination_number: '${props.mockDestination}',
  sender: '+989121111111',
  body: 'کد تأیید ورود شما: ۱۲۳۴۵',
  client_message_id: 'MSG-9876543210',
  source_system_id: 'GATEWAY-TEHRAN'
}

http = Net::HTTP.new(uri.host, uri.port)
request = Net::HTTP::Post.new(uri.request_uri)
request['Authorization'] = "Bearer #{token}"
request['Content-Type'] = 'application/json'
request['Accept'] = 'application/json'
request.body = payload.to_json

begin
  response = http.request(request)
  data = JSON.parse(response.body)

  case response.code.to_i
  when 201
    puts "پیام با موفقیت در سامانه ثبت شد. شناسه: #{data.dig('data', 'id')}"
  when 200
    puts "پیام تکراری قبلاً ثبت شده (Idempotent): #{data['message']}"
  else
    puts "خطای وب‌سرویس (#{response.code}): #{data['message']}"
  end
rescue StandardError => e
  puts "خطای ارتباط شبکه: #{e.message}"
end`,

    powershell: `$apiUrl = "${props.baseUrl}/api/v1/messages"
$token  = "${props.mockToken}"

$headers = @{
    "Authorization" = "Bearer $token"
    "Accept"        = "application/json"
}

$body = @{
    destination_number = "${props.mockDestination}"
    sender             = "+989121111111"
    body               = "کد تأیید ورود شما: ۱۲۳۴۵"
    client_message_id  = "MSG-9876543210"
    source_system_id   = "GATEWAY-TEHRAN"
} | ConvertTo-Json -Compress

try {
    $response = Invoke-RestMethod -Uri $apiUrl -Method Post -Headers $headers -Body $body -ContentType "application/json; charset=utf-8"
    if ($response.data.is_duplicate) {
        Write-Host "پیام تکراری است (Idempotent): $($response.message)" -ForegroundColor Yellow
    } else {
        Write-Host "پیام با موفقیت در سامانه ثبت شد! شناسه: $($response.data.id)" -ForegroundColor Green
    }
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    Write-Host "خطا در فراخوانی وب‌سرویس (کد $statusCode): $($_.Exception.Message)" -ForegroundColor Red
}`,
}));
</script>

<template>
    <GuestLayout>
        <Head title="مستندات جامع وب‌سرویس RESTful API" />

        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10 space-y-12">
            <!-- Header Section with Rich Aesthetics -->
            <div class="text-center space-y-4 max-w-3xl mx-auto">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs font-bold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>نسخه فعال وب‌سرویس: v1 • استاندارد RESTful</span>
                </div>
                <h1 class="text-3xl sm:text-4xl font-black text-slate-900 dark:text-white tracking-tight">
                    مستندات رسمی و راهنمای اتصال به API
                </h1>
                <p class="text-sm sm:text-base text-slate-600 dark:text-slate-300 leading-relaxed">
                    راهنمای یکپارچه‌سازی سامانه‌ها با گذرگاه ارتباطی سامانه مدیریت خطوط و ثبت پیام‌ها، همراه با قوانین اعتبارسنجی پیش‌شماره کامل و نمونه‌کدهای معتبر در ۱۱ زبان برنامه‌نویسی.
                </p>
            </div>

            <!-- MANDATORY ARCHITECTURAL DISCLAIMER CALLOUT (Rule 10) -->
            <div class="relative overflow-hidden rounded-2xl border-2 border-amber-400/80 bg-gradient-to-r from-amber-500/10 via-amber-500/5 to-transparent p-6 sm:p-7 shadow-lg shadow-amber-500/5">
                <div class="flex items-start gap-4">
                    <div class="w-12 h-12 rounded-xl bg-amber-500 text-white flex items-center justify-center shrink-0 shadow-md shadow-amber-500/25">
                        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    </div>
                    <div class="space-y-2">
                        <h2 class="text-lg font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span>ماهیت عملیاتی وب‌سرویس ثبت پیام</span>
                            <span class="px-2 py-0.5 text-[11px] font-bold rounded-md bg-amber-500/20 text-amber-800 dark:text-amber-300">نکته حیاتی</span>
                        </h2>
                        <p class="text-sm text-slate-700 dark:text-slate-200 leading-relaxed font-medium">
                            این وب‌سرویس پیام‌های ورودی را <span class="font-bold underline decoration-amber-500 underline-offset-4">منحصراً در پایگاه داده داخلی سامانه ثبت و ذخیره می‌کند</span> و <span class="font-bold underline decoration-amber-500 underline-offset-4">به هیچ عنوان پیامک (SMS) مخابراتی واقعی به اپراتورها ارسال نمی‌کند</span>. کلیه پیام‌های ثبت‌شده در کارتابل و رابط کاربری پیامک کاربر و مدیر نمایش داده خواهند شد.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Core Principles Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Authentication & Token Privacy -->
                <div class="glass-card p-6 rounded-2xl space-y-3 border border-slate-200/80 dark:border-white/10">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </div>
                    <h3 class="font-bold text-slate-900 dark:text-white text-base">احراز هویت و محرمانگی کلید</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        کلید دسترسی (API Token) صرفاً در پنل احراز هویت‌شده کاربر قابل صدور و مشاهده است. کلید در هدر درخواست به صورت <code class="font-mono text-indigo-600 dark:text-indigo-400 font-bold">Authorization: Bearer [TOKEN]</code> یا هدر اختصاصی <code class="font-mono text-indigo-600 dark:text-indigo-400">X-API-Key</code> ارسال می‌گردد.
                    </p>
                    <div class="pt-2">
                        <Link :href="route('login')" class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline inline-flex items-center gap-1">
                            <span>دریافت توکن در پنل کاربری</span>
                            <span>←</span>
                        </Link>
                    </div>
                </div>

                <!-- Number Prefix & Validation -->
                <div class="glass-card p-6 rounded-2xl space-y-3 border border-slate-200/80 dark:border-white/10">
                    <div class="w-10 h-10 rounded-xl bg-purple-50 dark:bg-purple-950/60 text-purple-600 dark:text-purple-400 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
                        </svg>
                    </div>
                    <h3 class="font-bold text-slate-900 dark:text-white text-base">پیش‌شماره کامل و کنترل مالکیت</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        شماره مقصد الزامی است و باید <span class="font-bold">همیشه با پیش‌شماره کامل سامانه</span> (مانند <code class="font-mono text-purple-600 dark:text-purple-400 font-bold">{{ defaultPrefix }}000010001</code>) ارسال شود. کاربر عادی فقط مجاز به ثبت پیام برای خطوط فعال حساب خود است و خطوط پلتفرم در اختیار ادمین است.
                    </p>
                </div>

                <!-- Idempotency & Rate Limit -->
                <div class="glass-card p-6 rounded-2xl space-y-3 border border-slate-200/80 dark:border-white/10">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold">
                        <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 4 23 10 17 10"></polyline>
                            <polyline points="1 20 1 14 7 14"></polyline>
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
                        </svg>
                    </div>
                    <h3 class="font-bold text-slate-900 dark:text-white text-base">جلوگیری از پیام تکراری و سقف نرخ</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        با ارسال <code class="font-mono text-emerald-600 dark:text-emerald-400">client_message_id</code>، سیستم با محدودیت دیتابیس از ثبت تکراری جلوگیری کرده و کد <code class="font-mono font-bold">200 OK</code> با فلگ <code class="font-mono">is_duplicate: true</code> برمی‌گرداند. سقف نرخ ۶۰ درخواست در دقیقه است.
                    </p>
                </div>
            </div>

            <!-- Endpoints Navigation Tabs -->
            <div class="space-y-6">
                <div class="flex items-center justify-between border-b border-slate-200 dark:border-white/10 pb-3">
                    <h2 class="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span>متدهای وب‌سرویس</span>
                        <span class="text-xs font-normal text-slate-500">API Reference</span>
                    </h2>
                    <div class="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs">
                        <button
                            type="button"
                            @click="activeEndpoint = 'store'"
                            class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                            :class="activeEndpoint === 'store' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            <span class="font-mono uppercase font-black text-[10px] bg-emerald-500 text-white px-1.5 py-0.5 rounded ml-1.5">POST</span>
                            <span>ارسال و ثبت پیام (یکپارچه)</span>
                        </button>
                        <button
                            type="button"
                            @click="activeEndpoint = 'list'"
                            class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                            :class="activeEndpoint === 'list' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            <span class="font-mono uppercase font-black text-[10px] bg-blue-500 text-white px-1.5 py-0.5 rounded ml-1.5">GET</span>
                            <span>فهرست پیام‌ها</span>
                        </button>
                        <button
                            type="button"
                            @click="activeEndpoint = 'poll'"
                            class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                            :class="activeEndpoint === 'poll' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            <span class="font-mono uppercase font-black text-[10px] bg-blue-500 text-white px-1.5 py-0.5 rounded ml-1.5">GET</span>
                            <span>استعلام سریع (Poll)</span>
                        </button>
                        <button
                            type="button"
                            @click="activeEndpoint = 'numbers'"
                            class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                            :class="activeEndpoint === 'numbers' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            <span class="font-mono uppercase font-black text-[10px] bg-blue-500 text-white px-1.5 py-0.5 rounded ml-1.5">GET</span>
                            <span>فهرست خطوط</span>
                        </button>
                        <button
                            type="button"
                            @click="activeEndpoint = 'ping'"
                            class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                            :class="activeEndpoint === 'ping' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            <span class="font-mono uppercase font-black text-[10px] bg-blue-500 text-white px-1.5 py-0.5 rounded ml-1.5">GET</span>
                            <span>تست توکن (Ping)</span>
                        </button>
                    </div>
                </div>

                <!-- Endpoint Detail: POST /api/v1/messages -->
                <div v-if="activeEndpoint === 'store'" class="glass-card p-6 sm:p-8 rounded-2xl border border-slate-200/80 dark:border-white/10 space-y-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-xs font-black bg-emerald-600 text-white px-2.5 py-1 rounded-lg">POST</span>
                            <span class="font-mono text-sm sm:text-base font-bold text-slate-900 dark:text-white" dir="ltr">/api/v1/messages</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="glass-pill text-[11px] font-bold text-indigo-600 dark:text-indigo-400">مجوز لازم: messages:write یا messages:send</span>
                            <span class="glass-pill text-[11px] font-bold text-emerald-600 dark:text-emerald-400">هزینه: رایگان (بدون کسر Coin)</span>
                            <span class="glass-pill text-[11px] font-bold text-amber-600 dark:text-amber-400">سقف: ۶۰ در دقیقه</span>
                        </div>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                        این وب‌سرویس جامع جهت <span class="font-bold">ارسال پیامک خروجی (تکی یا چندگانه/گروهی)</span> از خطوط سازمانی شما، و همچنین <span class="font-bold">دریافت و ثبت وب‌هوک پیامک‌های ورودی</span> در کارتابل سامانه با پشتیبانی کامل از Idempotency طراحی شده است.
                    </p>

                    <!-- Parameters Table -->
                    <div class="space-y-3">
                        <h4 class="font-bold text-sm text-slate-900 dark:text-white">پارامترهای بدنه درخواست (JSON Body)</h4>
                        <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-white/10">
                            <table class="w-full text-right text-xs">
                                <thead class="bg-slate-50 dark:bg-slate-900/60 text-slate-600 dark:text-slate-400 font-bold border-b border-slate-200 dark:border-white/10">
                                    <tr>
                                        <th class="p-3">نام فیلد</th>
                                        <th class="p-3">نوع</th>
                                        <th class="p-3">وضعیت</th>
                                        <th class="p-3">توضیحات و قوانین</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200/60 dark:divide-white/5">
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">destination_number / destinations</td>
                                        <td class="p-3 font-mono">string | array</td>
                                        <td class="p-3"><span class="text-rose-600 font-bold">الزامی</span></td>
                                        <td class="p-3 leading-relaxed">
                                            شماره مقصد (تکی یا آرایه‌ای از شماره‌ها جهت ارسال چندگانه/Bulk). شماره‌های شبکه GetNumber باید همراه با پیش‌شماره کامل سامانه ارسال شوند. فیلد <code class="font-mono">to</code> نیز به عنوان نام مستعار پشتیبانی می‌شود.
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">sender / from_number</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3"><span class="text-rose-600 font-bold">الزامی</span></td>
                                        <td class="p-3">خط فرستنده پیام (در ارسال خروجی باید متعلق به کاربر باشد). فیلدهای <code class="font-mono">from</code> یا <code class="font-mono">from_number_id</code> نیز پشتیبانی می‌شوند.</td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">body / message</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3"><span class="text-rose-600 font-bold">الزامی</span></td>
                                        <td class="p-3">متن کامل پیام (حداکثر ۵۰۰۰ کاراکتر با پشتیبانی کامل از UTF-8 و متون فارسی).</td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">direction</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3 text-slate-500">اختیاری</td>
                                        <td class="p-3">جهت پیام: <code class="font-mono">outbound</code> (پیش‌فرض هنگام ارسال از خطوط اختصاص‌یافته) یا <code class="font-mono">inbound</code> (دریافت وب‌هوک).</td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">client_message_id</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3 text-slate-500">اختیاری</td>
                                        <td class="p-3 leading-relaxed">
                                            شناسه یکتای پیام در سیستم فرستنده جهت تضمین Idempotency و ممانعت از ثبت تکراری پیام.
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">source_system_id</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3 text-slate-500">اختیاری</td>
                                        <td class="p-3">شناسه گیت‌وی یا سرور ارسال‌کننده (در صورت خالی بودن به توکن متصل می‌شود).</td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">source_time</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3 text-slate-500">اختیاری</td>
                                        <td class="p-3">زمان رخداد پیام در سرور مبدأ در قالب استاندارد ISO 8601.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Response Example -->
                    <div class="space-y-3">
                        <h4 class="font-bold text-sm text-slate-900 dark:text-white">پاسخ‌های استاندارد وب‌سرویس</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- 201 Created -->
                            <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-900 p-4 text-slate-200 text-xs font-mono space-y-2">
                                <div class="flex items-center justify-between text-[11px] pb-1 border-b border-white/10 text-emerald-400 font-bold">
                                    <span>201 Created (ثبت جدید)</span>
                                </div>
                                <pre dir="ltr" class="overflow-x-auto text-[11px]">{
  "success": true,
  "message": "پیام ورودی با موفقیت در سامانه ثبت گردید.",
  "data": {
    "id": 482,
    "destination_number": "000010001",
    "destination_title": "{{ defaultPrefix }} 000010001",
    "sender": "+989121111111",
    "body": "کد تأیید ورود شما: ۱۲۳۴۵",
    "client_message_id": "MSG-9876543210",
    "source_system_id": "GATEWAY-TEHRAN",
    "processing_status": "received",
    "is_duplicate": false
  }
}</pre>
                            </div>

                            <!-- 200 OK Duplicate -->
                            <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-900 p-4 text-slate-200 text-xs font-mono space-y-2">
                                <div class="flex items-center justify-between text-[11px] pb-1 border-b border-white/10 text-amber-400 font-bold">
                                    <span>200 OK (درخواست تکراری / Idempotent)</span>
                                </div>
                                <pre dir="ltr" class="overflow-x-auto text-[11px]">{
  "success": true,
  "message": "پیام پیش‌تر در سامانه ثبت شده است (درخواست تکراری / Idempotent).",
  "data": {
    "id": 482,
    "destination_number": "000010001",
    "is_duplicate": true
  }
}</pre>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Endpoint Detail: GET /api/v1/messages -->
                <div v-else-if="activeEndpoint === 'list'" class="glass-card p-6 sm:p-8 rounded-2xl border border-slate-200/80 dark:border-white/10 space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-xs font-black bg-blue-600 text-white px-2.5 py-1 rounded-lg">GET</span>
                        <span class="font-mono text-sm sm:text-base font-bold text-slate-900 dark:text-white" dir="ltr">/api/v1/messages</span>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        دریافت فهرست پیام‌های ورودی مرتبط با خطوط کاربر با صفحه‌بندی (Pagination) و فیلترهای جستجو، شماره مقصد و شماره فرستنده.
                    </p>
                    <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-900 p-4 text-slate-200 text-xs font-mono">
                        <pre dir="ltr" class="overflow-x-auto text-[11px]">{
  "success": true,
  "message": "فهرست پیام‌ها با موفقیت دریافت شد.",
  "pagination": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 34
  },
  "data": [
    {
      "id": 482,
      "destination_number": "000010001",
      "sender": "+989121111111",
      "body": "کد تأیید ورود شما: ۱۲۳۴۵",
      "server_received_at": "2026-10-24T20:10:00+03:30",
      "processing_status": "received"
    }
  ]
}</pre>
                    </div>
                </div>

                <!-- Endpoint Detail: GET /api/v1/messages/poll -->
                <div v-else-if="activeEndpoint === 'poll'" class="glass-card p-6 sm:p-8 rounded-2xl border border-slate-200/80 dark:border-white/10 space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-xs font-black bg-blue-600 text-white px-2.5 py-1 rounded-lg">GET</span>
                        <span class="font-mono text-sm sm:text-base font-bold text-slate-900 dark:text-white" dir="ltr">/api/v1/messages/poll?after_id=480</span>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        متد اختصاصی Polling با سرعت بالا جهت دریافت بلادرنگ پیام‌های جدید رسیده بعد از یک شناسه مشخص (<code class="font-mono font-bold text-indigo-600 dark:text-indigo-400">after_id</code>).
                    </p>
                </div>

                <!-- Endpoint Detail: GET /api/v1/numbers -->
                <div v-else-if="activeEndpoint === 'numbers'" class="glass-card p-6 sm:p-8 rounded-2xl border border-slate-200/80 dark:border-white/10 space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-xs font-black bg-blue-600 text-white px-2.5 py-1 rounded-lg">GET</span>
                        <span class="font-mono text-sm sm:text-base font-bold text-slate-900 dark:text-white" dir="ltr">/api/v1/numbers</span>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        دریافت فهرست خطوط فعال اختصاص‌یافته به حساب کاربری دارنده کلید API همراه با شماره با پیش‌شماره کامل و برچسب خط.
                    </p>
                </div>

                <!-- Endpoint Detail: GET /api/v1/ping -->
                <div v-else-if="activeEndpoint === 'ping'" class="glass-card p-6 sm:p-8 rounded-2xl border border-slate-200/80 dark:border-white/10 space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-xs font-black bg-blue-600 text-white px-2.5 py-1 rounded-lg">GET</span>
                        <span class="font-mono text-sm sm:text-base font-bold text-slate-900 dark:text-white" dir="ltr">/api/v1/ping</span>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        ساده‌ترین متد جهت بررسی صحت ارتباط شبکه، اعتبار کلید دسترسی (Token) و استعلام نقش کاربری.
                    </p>
                </div>
            </div>

            <!-- Comprehensive Code Examples in 11 Languages (Rule 8 & 9) -->
            <div class="space-y-6 pt-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 dark:border-white/10 pb-4">
                    <div>
                        <h2 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span>نمونه‌کدهای آماده در ۱۱ زبان برنامه‌نویسی</span>
                        </h2>
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
                            نمونه‌های اجرایی مطابق با قرارداد واقعی وب‌سرویس، شامل توکن فرضی، ساختار درخواست، مدیریت پاسخ و خطاها.
                        </p>
                    </div>

                    <!-- Language selector pills -->
                    <div class="flex items-center gap-1.5 overflow-x-auto pb-1 max-w-full">
                        <button
                            v-for="lang in languages"
                            :key="lang.key"
                            type="button"
                            @click="selectedLang = lang.key"
                            class="px-2.5 py-1.5 rounded-lg text-xs font-bold transition-all shrink-0 flex items-center gap-1.5"
                            :class="selectedLang === lang.key
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/20'
                                : 'glass-pill text-slate-700 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400'"
                        >
                            <span>{{ lang.icon }}</span>
                            <span>{{ lang.name }}</span>
                        </button>
                    </div>
                </div>

                <!-- Code Container -->
                <div class="relative rounded-2xl border border-slate-800 bg-slate-950 shadow-2xl overflow-hidden">
                    <!-- Code header -->
                    <div class="flex items-center justify-between px-5 py-3 border-b border-white/10 bg-slate-900/80">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full bg-rose-500/80"></span>
                            <span class="w-3 h-3 rounded-full bg-amber-500/80"></span>
                            <span class="w-3 h-3 rounded-full bg-emerald-500/80"></span>
                            <span class="text-xs font-mono font-bold text-slate-400 mr-2">
                                {{ languages.find(l => l.key === selectedLang)?.name }} Example
                            </span>
                        </div>
                        <button
                            type="button"
                            @click="copyCode(codeSnippets[selectedLang], selectedLang)"
                            class="px-3 py-1 rounded-md text-xs font-bold bg-white/10 hover:bg-white/20 text-white transition-colors flex items-center gap-1.5"
                        >
                            <svg v-if="copiedKey !== selectedLang" class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                            <svg v-else class="w-3.5 h-3.5 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            <span>{{ copiedKey === selectedLang ? 'کپی شد!' : 'کپی کد' }}</span>
                        </button>
                    </div>

                    <!-- Code block -->
                    <pre dir="ltr" class="p-5 overflow-x-auto text-xs sm:text-sm font-mono text-slate-200 leading-relaxed max-h-[520px]"><code>{{ codeSnippets[selectedLang] }}</code></pre>
                </div>
            </div>

            <!-- Error Handling Reference -->
            <div class="glass-card p-6 sm:p-8 rounded-2xl border border-slate-200/80 dark:border-white/10 space-y-4">
                <h3 class="text-lg font-black text-slate-900 dark:text-white">کدهای وضعیت و ساختار خطاهای استاندارد</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-rose-600 dark:text-rose-400">401 Unauthorized</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            عدم ارسال کلید دسترسی در هدر یا نامعتبر بودن/انقضای توکن (<code class="font-mono">MISSING_API_TOKEN</code> / <code class="font-mono">INVALID_API_TOKEN</code>).
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-amber-600 dark:text-amber-400">403 Forbidden</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            فقدان مجوز لازم در توکن، تلاش برای ثبت پیام روی خط کاربر دیگر یا خطوط اختصاصی پلتفرم (<code class="font-mono">FORBIDDEN</code>).
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-indigo-600 dark:text-indigo-400">422 Unprocessable</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            خطای اعتبارسنجی فیلدها مانند نبود پیش‌شماره کامل، طول نامعتبر شماره یا غیرفعال بودن دریافت پیامک روی خط (<code class="font-mono">VALIDATION_ERROR</code>).
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-purple-600 dark:text-purple-400">429 Too Many Requests</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            تجاوز از سقف مجاز ۶۰ درخواست در دقیقه برای هر توکن یا نشانی IP کاربر (<code class="font-mono">RATE_LIMIT_EXCEEDED</code>).
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400">201 Created</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            پیام جدید با موفقیت و به صورت اتمیک در پایگاه داده سامانه ثبت شد و آماده مشاهده در کارتابل است.
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-blue-600 dark:text-blue-400">200 OK (Idempotent)</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            درخواست با موفقیت پردازش شد و به دلیل تکراری بودن شناسه پیام، پیام پیشین بدون دوباره‌کاری بازگردانده شد.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
