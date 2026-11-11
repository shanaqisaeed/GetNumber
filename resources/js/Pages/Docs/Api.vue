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
    "body": "Your verification code: 12345",
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
    "body": "Your verification code: 12345",
    "client_message_id": "MSG-9876543210",
    "source_system_id": "GATEWAY-TEHRAN"
}

try:
    response = requests.post(url, json=payload, headers=headers, timeout=10)
    data = response.json()

    if response.status_code == 201:
        print("New message registered successfully. ID:", data["data"]["id"])
    elif response.status_code == 200 and data.get("data", {}).get("is_duplicate"):
        print("Valid idempotent request:", data["message"])
    else:
        print(f"API error ({response.status_code}):", data.get("message"))
except requests.exceptions.RequestException as e:
    print("Server connection error:", str(e))`,

    php: `<?php

$url = '${props.baseUrl}/api/v1/messages';
$token = '${props.mockToken}';

$payload = [
    'destination_number' => '${props.mockDestination}',
    'sender' => '+989121111111',
    'body' => 'Your verification code: 12345',
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
    echo "Connection error: {$error}\n";
    exit;
}

$data = json_decode($response, true);
if ($httpCode === 201) {
    echo "Message recorded successfully. ID: " . $data['data']['id'] . "\n";
} elseif ($httpCode === 200 && ($data['data']['is_duplicate'] ?? false)) {
    echo "Duplicate message already registered: " . $data['message'] . "\n";
} else {
    echo "Error ({$httpCode}): " . ($data['message'] ?? 'Validation or server error') . "\n";
}`,

    javascript: `const apiUrl = '${props.baseUrl}/api/v1/messages';
const apiToken = '${props.mockToken}';

async function recordInboundMessage() {
  const payload = {
    destination_number: '${props.mockDestination}',
    sender: '+989121111111',
    body: 'Your verification code: 12345',
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
      console.log('Message registered successfully:', data.data);
    } else if (res.status === 200 && data.data?.is_duplicate) {
      console.warn('Valid idempotent request:', data.message);
    } else {
      console.error(\`Error (\${res.status}):\`, data.message, data.errors || '');
    }
  } catch (err) {
    console.error('Failed to connect to API:', err);
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
      console.log(\`New message with ID \${result.data?.id} registered successfully.\`);
    } else if (response.status === 200 && result.data?.is_duplicate) {
      console.warn('Duplicate message (Idempotent):', result.message);
    } else {
      console.error(\`Server error [\${result.code || response.status}]: \${result.message}\`);
    }
  } catch (error) {
    console.error('Network connection error:', error);
  }
}

sendInboundMessage({
  destination_number: '${props.mockDestination}',
  sender: '+989121111111',
  body: 'Your verification code: 12345',
  client_message_id: 'MSG-9876543210',
  source_system_id: 'GATEWAY-TEHRAN',
});`,

    nodejs: `// Requires Node.js 18+ or node-fetch package
const API_URL = '${props.baseUrl}/api/v1/messages';
const TOKEN = '${props.mockToken}';

async function main() {
  const payload = {
    destination_number: '${props.mockDestination}',
    sender: '+989121111111',
    body: 'Your verification code: 12345',
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
      console.log('Success - Message ID:', body.data.id);
    } else if (res.status === 200 && body.data?.is_duplicate) {
      console.log('Duplicate message (already recorded):', body.message);
    } else {
      console.error(\`Error \${res.status}:\`, body.message, body.errors ?? '');
    }
  } catch (err) {
    console.error('Network connection error:', err.message);
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
            "body": "Your verification code: 12345",
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
                System.out.println("Message registered successfully: " + response.body());
            } else if (statusCode == 200) {
                System.out.println("Valid idempotent request: " + response.body());
            } else {
                System.err.println("API error (" + statusCode + "): " + response.body());
            }
        } catch (Exception e) {
            System.err.println("Connection error: " + e.getMessage());
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
            body = "Your verification code: 12345",
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
                Console.WriteLine($"Inbound message registered successfully:\\n{responseBody}");
            }
            else if (response.StatusCode == System.Net.HttpStatusCode.OK)
            {
                Console.WriteLine($"Valid idempotent request:\\n{responseBody}");
            }
            else
            {
                Console.WriteLine($"Server error [{(int)response.StatusCode}]:\\n{responseBody}");
            }
        }
        catch (HttpRequestException ex)
        {
            Console.WriteLine($"Network connection error: {ex.Message}");
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
		Body:              "Your verification code: 12345",
		ClientMessageId:   "MSG-9876543210",
		SourceSystemId:    "GATEWAY-TEHRAN",
	}

	jsonData, err := json.Marshal(payload)
	if err != nil {
		fmt.Printf("Error serializing data: %v\\n", err)
		return
	}

	req, err := http.NewRequest("POST", apiUrl, bytes.NewBuffer(jsonData))
	if err != nil {
		fmt.Printf("Error creating request: %v\\n", err)
		return
	}

	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		fmt.Printf("Server connection error: %v\\n", err)
		return
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode == http.StatusCreated {
		fmt.Printf("Message registered successfully: %s\\n", string(body))
	} else if resp.StatusCode == http.StatusOK {
		fmt.Printf("Valid idempotent message: %s\\n", string(body))
	} else {
		fmt.Printf("API error [%d]: %s\\n", resp.StatusCode, string(body))
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
  body: 'Your verification code: 12345',
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
    puts "Message registered successfully. ID: #{data.dig('data', 'id')}"
  when 200
    puts "Duplicate message previously recorded: #{data['message']}"
  else
    puts "API error (#{response.code}): #{data['message']}"
  end
rescue StandardError => e
  puts "Network connection error: #{e.message}"
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
    body               = "Your verification code: 12345"
    client_message_id  = "MSG-9876543210"
    source_system_id   = "GATEWAY-TEHRAN"
} | ConvertTo-Json -Compress

try {
    $response = Invoke-RestMethod -Uri $apiUrl -Method Post -Headers $headers -Body $body -ContentType "application/json; charset=utf-8"
    if ($response.data.is_duplicate) {
        Write-Host "Duplicate message (Idempotent): $($response.message)" -ForegroundColor Yellow
    } else {
        Write-Host "Message registered successfully! ID: $($response.data.id)" -ForegroundColor Green
    }
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    Write-Host "API call error (Code $statusCode): $($_.Exception.Message)" -ForegroundColor Red
}`,
}));
</script>

<template>
    <GuestLayout>
        <Head :title="__('docs.page_title')" />

        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-10 space-y-12">
            <!-- Header Section with Rich Aesthetics -->
            <div class="text-center space-y-4 max-w-3xl mx-auto">
                <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full text-xs font-bold bg-indigo-50 dark:bg-indigo-950/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>{{ __('docs.active_version_badge') }}</span>
                </div>
                <h1 class="text-3xl sm:text-4xl font-black text-slate-900 dark:text-white tracking-tight">
                    {{ __('docs.heading') }}
                </h1>
                <p class="text-sm sm:text-base text-slate-600 dark:text-slate-300 leading-relaxed">
                    {{ __('docs.subheading') }}
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
                            <span>{{ __('docs.disclaimer_title') }}</span>
                            <span class="px-2 py-0.5 text-[11px] font-bold rounded-md bg-amber-500/20 text-amber-800 dark:text-amber-300">{{ __('docs.critical_badge') }}</span>
                        </h2>
                        <p class="text-sm text-slate-700 dark:text-slate-200 leading-relaxed font-medium" v-html="__('docs.disclaimer_text')"></p>
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
                    <h3 class="font-bold text-slate-900 dark:text-white text-base">{{ __('docs.principles.auth_title') }}</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        {{ __('docs.principles.auth_desc', { bearer: 'Authorization: Bearer [TOKEN]', header: 'X-API-Key' }) }}
                    </p>
                    <div class="pt-2">
                        <Link :href="route('login')" class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline inline-flex items-center gap-1">
                            <span>{{ __('docs.principles.get_token_link') }}</span>
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
                    <h3 class="font-bold text-slate-900 dark:text-white text-base">{{ __('docs.principles.prefix_title') }}</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        {{ __('docs.principles.prefix_desc', { example: defaultPrefix + '000010001' }) }}
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
                    <h3 class="font-bold text-slate-900 dark:text-white text-base">{{ __('docs.principles.idempotency_title') }}</h3>
                    <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                        {{ __('docs.principles.idempotency_desc', { field: 'client_message_id', code: '200 OK', flag: 'is_duplicate: true' }) }}
                    </p>
                </div>
            </div>

            <!-- Endpoints Navigation Tabs -->
            <div class="space-y-6">
                <div class="flex items-center justify-between border-b border-slate-200 dark:border-white/10 pb-3">
                    <h2 class="text-xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                        <span>{{ __('docs.api_reference_title') }}</span>
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
                            <span>{{ __('docs.endpoints.store_tab') }}</span>
                        </button>
                        <button
                            type="button"
                            @click="activeEndpoint = 'list'"
                            class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                            :class="activeEndpoint === 'list' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            <span class="font-mono uppercase font-black text-[10px] bg-blue-500 text-white px-1.5 py-0.5 rounded ml-1.5">GET</span>
                            <span>{{ __('docs.endpoints.list_tab') }}</span>
                        </button>
                        <button
                            type="button"
                            @click="activeEndpoint = 'poll'"
                            class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                            :class="activeEndpoint === 'poll' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            <span class="font-mono uppercase font-black text-[10px] bg-blue-500 text-white px-1.5 py-0.5 rounded ml-1.5">GET</span>
                            <span>{{ __('docs.endpoints.poll_tab') }}</span>
                        </button>
                        <button
                            type="button"
                            @click="activeEndpoint = 'numbers'"
                            class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                            :class="activeEndpoint === 'numbers' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            <span class="font-mono uppercase font-black text-[10px] bg-blue-500 text-white px-1.5 py-0.5 rounded ml-1.5">GET</span>
                            <span>{{ __('docs.endpoints.numbers_tab') }}</span>
                        </button>
                        <button
                            type="button"
                            @click="activeEndpoint = 'ping'"
                            class="px-3 py-1.5 rounded-lg font-bold transition-colors"
                            :class="activeEndpoint === 'ping' ? 'bg-indigo-600 text-white' : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        >
                            <span class="font-mono uppercase font-black text-[10px] bg-blue-500 text-white px-1.5 py-0.5 rounded ml-1.5">GET</span>
                            <span>{{ __('docs.endpoints.ping_tab') }}</span>
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
                            <span class="glass-pill text-[11px] font-bold text-indigo-600 dark:text-indigo-400">{{ __('docs.endpoints.perm_store') }}</span>
                            <span class="glass-pill text-[11px] font-bold text-emerald-600 dark:text-emerald-400">{{ __('docs.endpoints.cost_free') }}</span>
                            <span class="glass-pill text-[11px] font-bold text-amber-600 dark:text-amber-400">{{ __('docs.endpoints.rate_store') }}</span>
                        </div>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">
                        {{ __('docs.endpoints.desc_store') }}
                    </p>

                    <!-- Parameters Table -->
                    <div class="space-y-3">
                        <h4 class="font-bold text-sm text-slate-900 dark:text-white">{{ __('docs.endpoints.params_title') }}</h4>
                        <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-white/10">
                            <table class="w-full text-right text-xs">
                                <thead class="bg-slate-50 dark:bg-slate-900/60 text-slate-600 dark:text-slate-400 font-bold border-b border-slate-200 dark:border-white/10">
                                    <tr>
                                        <th class="p-3">{{ __('docs.endpoints.col_name') }}</th>
                                        <th class="p-3">{{ __('docs.endpoints.col_type') }}</th>
                                        <th class="p-3">{{ __('docs.endpoints.col_status') }}</th>
                                        <th class="p-3">{{ __('docs.endpoints.col_desc') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200/60 dark:divide-white/5">
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">destination_number / destinations</td>
                                        <td class="p-3 font-mono">string | array</td>
                                        <td class="p-3"><span class="text-rose-600 font-bold">{{ __('docs.endpoints.required') }}</span></td>
                                        <td class="p-3 leading-relaxed">
                                            {{ __('docs.endpoints.param_destination_desc', { to: 'to' }) }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">sender / from_number</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3"><span class="text-rose-600 font-bold">{{ __('docs.endpoints.required') }}</span></td>
                                        <td class="p-3">{{ __('docs.endpoints.param_sender_desc', { from: 'from', from_id: 'from_number_id' }) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">body / message</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3"><span class="text-rose-600 font-bold">{{ __('docs.endpoints.required') }}</span></td>
                                        <td class="p-3">{{ __('docs.endpoints.param_body_desc') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">direction</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3 text-slate-500">{{ __('docs.endpoints.optional') }}</td>
                                        <td class="p-3">{{ __('docs.endpoints.param_direction_desc', { outbound: 'outbound', inbound: 'inbound' }) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">client_message_id</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3 text-slate-500">{{ __('docs.endpoints.optional') }}</td>
                                        <td class="p-3 leading-relaxed">
                                            {{ __('docs.endpoints.param_client_msg_desc') }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">source_system_id</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3 text-slate-500">{{ __('docs.endpoints.optional') }}</td>
                                        <td class="p-3">{{ __('docs.endpoints.param_source_system_desc') }}</td>
                                    </tr>
                                    <tr>
                                        <td class="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400" dir="ltr">source_time</td>
                                        <td class="p-3 font-mono">string</td>
                                        <td class="p-3 text-slate-500">{{ __('docs.endpoints.optional') }}</td>
                                        <td class="p-3">{{ __('docs.endpoints.param_source_time_desc') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Response Example -->
                    <div class="space-y-3">
                        <h4 class="font-bold text-sm text-slate-900 dark:text-white">{{ __('docs.endpoints.responses_title') }}</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- 201 Created -->
                            <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-900 p-4 text-slate-200 text-xs font-mono space-y-2">
                                <div class="flex items-center justify-between text-[11px] pb-1 border-b border-white/10 text-emerald-400 font-bold">
                                    <span>{{ __('docs.endpoints.resp_201_title') }}</span>
                                </div>
                                <pre dir="ltr" class="overflow-x-auto text-[11px]">{
  "success": true,
  "message": "Inbound message registered successfully.",
  "data": {
    "id": 482,
    "destination_number": "000010001",
    "destination_title": "{{ defaultPrefix }} 000010001",
    "sender": "+989121111111",
    "body": "Your verification code: 12345",
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
                                    <span>{{ __('docs.endpoints.resp_200_title') }}</span>
                                </div>
                                <pre dir="ltr" class="overflow-x-auto text-[11px]">{
  "success": true,
  "message": "Message previously recorded (Idempotent).",
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
                        {{ __('docs.endpoints.desc_list') }}
                    </p>
                    <div class="rounded-xl border border-slate-200 dark:border-white/10 bg-slate-900 p-4 text-slate-200 text-xs font-mono">
                        <pre dir="ltr" class="overflow-x-auto text-[11px]">{
  "success": true,
  "message": "Message list retrieved successfully.",
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
      "body": "Your verification code: 12345",
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
                        {{ __('docs.endpoints.desc_poll', { field: 'after_id' }) }}
                    </p>
                </div>

                <!-- Endpoint Detail: GET /api/v1/numbers -->
                <div v-else-if="activeEndpoint === 'numbers'" class="glass-card p-6 sm:p-8 rounded-2xl border border-slate-200/80 dark:border-white/10 space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-xs font-black bg-blue-600 text-white px-2.5 py-1 rounded-lg">GET</span>
                        <span class="font-mono text-sm sm:text-base font-bold text-slate-900 dark:text-white" dir="ltr">/api/v1/numbers</span>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ __('docs.endpoints.desc_numbers') }}
                    </p>
                </div>

                <!-- Endpoint Detail: GET /api/v1/ping -->
                <div v-else-if="activeEndpoint === 'ping'" class="glass-card p-6 sm:p-8 rounded-2xl border border-slate-200/80 dark:border-white/10 space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-xs font-black bg-blue-600 text-white px-2.5 py-1 rounded-lg">GET</span>
                        <span class="font-mono text-sm sm:text-base font-bold text-slate-900 dark:text-white" dir="ltr">/api/v1/ping</span>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ __('docs.endpoints.desc_ping') }}
                    </p>
                </div>
            </div>

            <!-- Comprehensive Code Examples in 11 Languages (Rule 8 & 9) -->
            <div class="space-y-6 pt-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 dark:border-white/10 pb-4">
                    <div>
                        <h2 class="text-xl sm:text-2xl font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span>{{ __('docs.code_examples_title') }}</span>
                        </h2>
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
                            {{ __('docs.code_examples_desc') }}
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
                            <span>{{ copiedKey === selectedLang ? __('docs.copied') : __('docs.copy_code') }}</span>
                        </button>
                    </div>

                    <!-- Code block -->
                    <pre dir="ltr" class="p-5 overflow-x-auto text-xs sm:text-sm font-mono text-slate-200 leading-relaxed max-h-[520px]"><code>{{ codeSnippets[selectedLang] }}</code></pre>
                </div>
            </div>

            <!-- Error Handling Reference -->
            <div class="glass-card p-6 sm:p-8 rounded-2xl border border-slate-200/80 dark:border-white/10 space-y-4">
                <h3 class="text-lg font-black text-slate-900 dark:text-white">{{ __('docs.errors_title') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-xs">
                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-rose-600 dark:text-rose-400">401 Unauthorized</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            {{ __('docs.errors.err_401_desc', { codes: 'MISSING_API_TOKEN / INVALID_API_TOKEN' }) }}
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-amber-600 dark:text-amber-400">403 Forbidden</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            {{ __('docs.errors.err_403_desc', { codes: 'FORBIDDEN' }) }}
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-indigo-600 dark:text-indigo-400">422 Unprocessable</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            {{ __('docs.errors.err_422_desc', { codes: 'VALIDATION_ERROR' }) }}
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-purple-600 dark:text-purple-400">429 Too Many Requests</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            {{ __('docs.errors.err_429_desc', { codes: 'RATE_LIMIT_EXCEEDED' }) }}
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-emerald-600 dark:text-emerald-400">201 Created</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            {{ __('docs.errors.err_201_desc') }}
                        </p>
                    </div>

                    <div class="p-3.5 rounded-xl border border-slate-200 dark:border-white/10 bg-white/50 dark:bg-slate-900/40 space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span class="font-mono font-bold text-blue-600 dark:text-blue-400">200 OK (Idempotent)</span>
                        </div>
                        <p class="text-slate-600 dark:text-slate-400 leading-relaxed">
                            {{ __('docs.errors.err_200_desc') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
