<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;

class CheckBridgePortsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bridge:check-ports
                            {host? : Destination Bridge Server IP or Hostname}
                            {--ports= : Comma-separated list of ports to test (e.g. 25,80,443,587,8080,49000)}
                            {--timeout=2 : Timeout in seconds per port}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test outbound TCP connectivity from this server to destination Voice Bridge server across candidate ports';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $host = $this->argument('host') ?: SystemSetting::get('voice_bridge_host', '127.0.0.1');
        $portsOption = $this->option('ports');
        $timeout = (float) $this->option('timeout') ?: 2.0;

        if ($portsOption) {
            $ports = array_filter(array_map('trim', explode(',', $portsOption)), 'is_numeric');
            $ports = array_map('intval', $ports);
        } else {
            // Default candidate ports commonly used or tested
            $configuredApi = (int) SystemSetting::get('voice_bridge_api_port', 49000);
            $configuredWs = (int) SystemSetting::get('voice_bridge_ws_port', 49001);
            $ports = array_values(array_unique([
                $configuredApi,
                $configuredWs,
                21, 25, 53, 80, 110, 111, 143, 443, 465,
                579, 587, 783, 953, 993, 995, 2077, 2078,
                2079, 2080, 2082, 2083, 2086, 2087, 2091,
                2095, 2096, 3101, 3306, 4190, 57563, 5960,
                5959, 7080, 8000, 8123, 8340, 8787, 9090,
                9095, 9096, 9097, 9098, 9099, 9919, 10050,
                27217, 65368, 59630, 65530,
            ]));
            sort($ports);
        }

        $this->info("==================================================================");
        $this->info("🔍 Outbound Port Connectivity Prober");
        $this->info("🎯 Destination Host: {$host}");
        $this->info("⏱️  Timeout: {$timeout} seconds per port");
        $this->info("==================================================================");

        $results = [];
        $openCount = 0;

        foreach ($ports as $port) {
            $this->output->write("Testing port {$port}... ");
            $start = microtime(true);
            $errno = 0;
            $errstr = '';

            $fp = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT
            );

            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            if ($fp) {
                fclose($fp);
                $this->line("<fg=green>OPEN ({$latencyMs}ms)</>");
                $results[] = [
                    'port' => $port,
                    'status' => 'OPEN / CONNECTED',
                    'latency' => "{$latencyMs} ms",
                    'details' => 'Handshake succeeded',
                ];
                $openCount++;
            } else {
                $status = ($errno === 110 || str_contains($errstr, 'timed out') || str_contains($errstr, 'Operation timed out'))
                    ? 'FILTERED / TIMED OUT'
                    : 'CLOSED / REFUSED';
                $this->line("<fg=red>{$status}</> ({$errstr})");
                $results[] = [
                    'port' => $port,
                    'status' => $status,
                    'latency' => "{$latencyMs} ms",
                    'details' => $errstr ?: "Error code: {$errno}",
                ];
            }
        }

        $this->newLine();
        $this->table(['Port', 'Status', 'Latency', 'Details'], $results);

        if ($openCount > 0) {
            $this->info("🎉 Found {$openCount} open port(s) that can establish connection to {$host}!");
        } else {
            $this->warn("⚠️  None of the tested ports could connect. Check firewall on both servers.");
        }

        return Command::SUCCESS;
    }
}

