<?php

/*
|--------------------------------------------------------------------------
| Silent Guardian Service - Pure Monitoring Only
|--------------------------------------------------------------------------
|
| Service yang HANYA monitoring dan alert, TIDAK PERNAH mengubah data.
| Berjalan 100% otomatis di background tanpa intervensi user.
| 
| PRINSIP UTAMA:
| - TIDAK mengubah rekaman stok lama
| - TIDAK mengubah data transaksi lama  
| - TIDAK memengaruhi transaksi hari ini
| - TIDAK memengaruhi transaksi kedepannya
| - HANYA monitoring dan alert
|
*/

require_once __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SilentGuardianService
{
    private $pidFile;
    private $logFile;
    private $alertFile;
    private $running = true;
    private $checkInterval = 1800; // 30 minutes
    
    public function __construct()
    {
        $this->pidFile = storage_path('logs/silent_guardian.pid');
        $this->logFile = storage_path('logs/silent_guardian.log');
        $this->alertFile = storage_path('logs/silent_guardian_alerts.log');
        
        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    public function start()
    {
        if ($this->isRunning()) {
            $this->log("Service already running");
            echo "Silent Guardian is already running\n";
            return;
        }
        
        $this->log("Starting Silent Guardian Service - Pure Monitoring Mode");
        
        // Save PID
        file_put_contents($this->pidFile, getmypid());
        
        echo "Silent Guardian Service started (PID: " . getmypid() . ")\n";
        echo "Mode: Pure Monitoring (NO DATA CHANGES)\n";
        echo "Interval: {$this->checkInterval} seconds\n";
        echo "Log: {$this->logFile}\n\n";
        
        // Register signal handlers for graceful shutdown (if available)
        if (function_exists('pcntl_signal')) {
            pcntl_signal(15, [$this, 'shutdown']); // SIGTERM
            pcntl_signal(2, [$this, 'shutdown']);  // SIGINT
        }
        
        $this->runMonitoringLoop();
    }
    
    public function stop()
    {
        if (!$this->isRunning()) {
            echo "Silent Guardian is not running\n";
            return;
        }
        
        $pid = $this->getPid();
        $this->log("Stopping Silent Guardian Service (PID: $pid)");
        
        // Try to terminate gracefully
        if (function_exists('posix_kill')) {
            posix_kill($pid, 15); // SIGTERM
        } else {
            // Windows
            exec("taskkill /PID $pid /F 2>nul");
        }
        
        unlink($this->pidFile);
        echo "Silent Guardian Service stopped\n";
    }
    
    public function status()
    {
        echo "\n=== SILENT GUARDIAN STATUS ===\n";
        
        if ($this->isRunning()) {
            $pid = $this->getPid();
            echo "Status: ✅ RUNNING (PID: $pid)\n";
            echo "Mode: 🛡️ Pure Monitoring\n";
            echo "Interval: {$this->checkInterval} seconds\n";
            
            if (file_exists($this->logFile)) {
                $lastModified = date('Y-m-d H:i:s', filemtime($this->logFile));
                echo "Last Check: $lastModified\n";
            }
        } else {
            echo "Status: ❌ NOT RUNNING\n";
        }
        
        echo "Log File: {$this->logFile}\n";
        echo "Alert File: {$this->alertFile}\n";
        echo "\n";
    }
    
    private function runMonitoringLoop()
    {
        $checkCount = 0;
        
        while ($this->running) {
            try {
                $checkCount++;
                $startTime = microtime(true);
                
                $this->log("=== Check #{$checkCount} Started ===");
                
                // PURE MONITORING - NO DATA CHANGES
                $this->monitorBaselineIntegrity();
                $this->monitorRecentTransactions();
                $this->monitorStockAnomalies();
                $this->monitorSystemHealth();
                
                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $this->log("=== Check #{$checkCount} Completed in {$duration}ms ===");
                
                // Sleep until next check
                sleep($this->checkInterval);
                
            } catch (Exception $e) {
                $this->log("ERROR in monitoring loop: " . $e->getMessage());
                $this->alert("CRITICAL", "Monitoring loop error: " . $e->getMessage());
                
                // Sleep before retry
                sleep(300); // 5 minutes
            }
        }
    }
    
    private function monitorBaselineIntegrity()
    {
        $this->log("🔍 Monitoring baseline integrity...");
        
        $baselineCount = DB::table('baseline_stok_snapshot')->count();
        $produkCount = DB::table('produk')->count();
        
        if ($baselineCount === 0) {
            $this->alert("WARNING", "No baseline snapshot found - system unprotected");
            return;
        }
        
        $coverage = round(($baselineCount / $produkCount) * 100, 1);
        $this->log("   📊 Baseline coverage: {$coverage}% ({$baselineCount}/{$produkCount})");
        
        if ($coverage < 80) {
            $this->alert("WARNING", "Baseline coverage low: {$coverage}%");
        }
        
        // Check if baseline is recent
        $latestBaseline = DB::table('baseline_stok_snapshot')
            ->orderBy('snapshot_date', 'desc')
            ->first();
            
        if ($latestBaseline) {
            $baselineAge = Carbon::parse($latestBaseline->snapshot_date)->diffInDays(now());
            $this->log("   📅 Baseline age: {$baselineAge} days");
            
            if ($baselineAge > 30) {
                $this->alert("INFO", "Baseline is {$baselineAge} days old - consider refresh");
            }
        }
    }
    
    private function monitorRecentTransactions()
    {
        $this->log("📈 Monitoring recent transactions...");
        
        // Check transactions in last hour
        $recentTransactions = DB::table('future_transaction_tracking')
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();
            
        $this->log("   🔄 Recent transactions (1h): {$recentTransactions}");
        
        // Check for anomalies in recent transactions
        $anomalies = DB::table('future_transaction_tracking')
            ->where('anomaly_detected', true)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();
            
        if ($anomalies > 0) {
            $this->alert("WARNING", "Found {$anomalies} transaction anomalies in last hour");
            $this->log("   ⚠️ Anomalies detected: {$anomalies}");
        } else {
            $this->log("   ✅ No anomalies detected");
        }
    }
    
    private function monitorStockAnomalies()
    {
        $this->log("🚨 Monitoring stock anomalies...");
        
        // Check for negative stock (but DON'T fix)
        $negativeStock = DB::table('produk')
            ->where('stok', '<', 0)
            ->count();
            
        if ($negativeStock > 0) {
            $this->alert("CRITICAL", "Found {$negativeStock} products with negative stock");
            $this->log("   ❌ Negative stock products: {$negativeStock}");
        } else {
            $this->log("   ✅ No negative stock detected");
        }
        
        // Check for extremely high stock changes
        $highChanges = DB::table('future_transaction_tracking')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->where(function($query) {
                $query->where('stok_change', '>', 1000)
                      ->orWhere('stok_change', '<', -1000);
            })
            ->count();
            
        if ($highChanges > 0) {
            $this->alert("WARNING", "Found {$highChanges} extreme stock changes (>1000 units) in last 24h");
            $this->log("   ⚠️ Extreme stock changes: {$highChanges}");
        } else {
            $this->log("   ✅ No extreme stock changes");
        }
    }
    
    private function monitorSystemHealth()
    {
        $this->log("💚 Monitoring system health...");
        
        // Check database connection
        try {
            DB::select('SELECT 1');
            $this->log("   ✅ Database connection OK");
        } catch (Exception $e) {
            $this->alert("CRITICAL", "Database connection failed: " . $e->getMessage());
            return;
        }
        
        // Check table existence
        $requiredTables = ['produk', 'baseline_stok_snapshot', 'future_transaction_tracking'];
        foreach ($requiredTables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $this->log("   ✅ Table {$table} exists");
            } else {
                $this->alert("CRITICAL", "Required table {$table} missing");
            }
        }
        
        // Check disk space (basic check)
        $logSize = file_exists($this->logFile) ? filesize($this->logFile) : 0;
        if ($logSize > 10 * 1024 * 1024) { // 10MB
            $this->alert("INFO", "Log file size is " . round($logSize/1024/1024, 1) . "MB - consider rotation");
        }
    }
    
    private function isRunning()
    {
        if (!file_exists($this->pidFile)) {
            return false;
        }
        
        $pid = trim(file_get_contents($this->pidFile));
        
        // Check if process exists (Windows)
        $output = shell_exec("tasklist /FI \"PID eq {$pid}\" 2>nul");
        return strpos($output, $pid) !== false;
    }
    
    private function getPid()
    {
        return file_exists($this->pidFile) ? trim(file_get_contents($this->pidFile)) : null;
    }
    
    private function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}\n";
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        echo $logEntry;
    }
    
    private function alert($level, $message)
    {
        $timestamp = date('Y-m-d H:i:s');
        $alertEntry = "[{$timestamp}] [{$level}] {$message}\n";
        file_put_contents($this->alertFile, $alertEntry, FILE_APPEND | LOCK_EX);
        
        // Also log to main log
        $this->log("🚨 ALERT [{$level}]: {$message}");
    }
    
    public function shutdown()
    {
        $this->running = false;
        $this->log("Shutdown signal received");
    }
}

// Command line interface
if (isset($argv[1])) {
    $service = new SilentGuardianService();
    
    switch ($argv[1]) {
        case 'start':
            $service->start();
            break;
        case 'stop':
            $service->stop();
            break;
        case 'restart':
            $service->stop();
            sleep(2);
            $service->start();
            break;
        case 'status':
            $service->status();
            break;
        default:
            echo "Usage: php silent_guardian_service.php {start|stop|restart|status}\n";
    }
} else {
    echo "Silent Guardian Service - Pure Monitoring Mode\n";
    echo "Usage: php silent_guardian_service.php {start|stop|restart|status}\n";
    echo "\nThis service ONLY monitors and alerts - NEVER changes any data.\n";
}
