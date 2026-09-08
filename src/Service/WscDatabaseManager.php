<?php

namespace Drupal\wsc_analytics\Service;

use Drupal\Core\Database\Database;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PDO;
use Exception;

/**
 * Manages WSC dual SQLite databases (Queue & Reports) and Drupal Database connections.
 */
class WscDatabaseManager {

  /** @var \Drupal\Core\File\FileSystemInterface */
  protected $fileSystem;

  /** @var \Drupal\Core\Config\ConfigFactoryInterface */
  protected $configFactory;

  /** @var \Drupal\Core\Logger\LoggerChannelInterface */
  protected $logger;

  /** @var PDO|null */
  protected $queuePdo = null;

  /** @var PDO|null */
  protected $reportsPdo = null;

  /**
   * Constructor.
   */
  public function __construct(
    FileSystemInterface $file_system,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->fileSystem = $file_system;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('wsc_analytics');

    $this->initStorageDirectory();
    $this->initDatabaseConnections();
  }

  /**
   * Ensure storage directory exists and is secured against direct HTTP downloads.
   */
  public function getStorageDir(): string {
    $dir = 'private://wsc_analytics';
    if (!file_exists($dir)) {
      $dir = 'public://wsc_analytics';
    }
    $real_dir = $this->fileSystem->realpath($dir);
    if (!$real_dir) {
      $real_dir = DRUPAL_ROOT . '/sites/default/files/wsc_analytics';
    }

    if (!is_dir($real_dir)) {
      @mkdir($real_dir, 0750, true);
    }

    // Security Hardening: write .htaccess, web.config, index.php
    $htaccess = $real_dir . '/.htaccess';
    if (!file_exists($htaccess)) {
      @file_put_contents($htaccess, "Require all denied\nDeny from all\n");
    }
    $webconfig = $real_dir . '/web.config';
    if (!file_exists($webconfig)) {
      @file_put_contents($webconfig, '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
    }
    $indexphp = $real_dir . '/index.php';
    if (!file_exists($indexphp)) {
      @file_put_contents($indexphp, '<?php // Silence is golden.');
    }

    return $real_dir;
  }

  /**
   * Get Queue SQLite file path.
   */
  public function getQueueDbPath(): string {
    return $this->getStorageDir() . '/queue_temp.sqlite';
  }

  /**
   * Get Reports SQLite file path.
   */
  public function getReportsDbPath(): string {
    return $this->getStorageDir() . '/reports_temp.sqlite';
  }

  /**
   * Initialize PDO connections with WAL mode and register Drupal Database connection for Views.
   */
  protected function initDatabaseConnections(): void {
    $this->initQueueSchema();
    $this->initReportsSchema();
    $this->registerDrupalReportsConnection();
  }

  /**
   * Get PDO instance for Write Queue.
   */
  public function getQueuePdo(): PDO {
    if ($this->queuePdo === null) {
      $path = $this->getQueueDbPath();
      $this->queuePdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]);
      $this->queuePdo->exec('PRAGMA journal_mode = WAL;');
      $this->queuePdo->exec('PRAGMA synchronous = NORMAL;');
      $this->queuePdo->exec('PRAGMA busy_timeout = 5000;');
    }
    return $this->queuePdo;
  }

  /**
   * Get PDO instance for Reports Database.
   */
  public function getReportsPdo(): PDO {
    if ($this->reportsPdo === null) {
      $path = $this->getReportsDbPath();
      $this->reportsPdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]);
      $this->reportsPdo->exec('PRAGMA journal_mode = WAL;');
      $this->reportsPdo->exec('PRAGMA synchronous = NORMAL;');
      $this->reportsPdo->exec('PRAGMA busy_timeout = 5000;');
    }
    return $this->reportsPdo;
  }

  /**
   * Initialize Write Queue table schema.
   */
  public function initQueueSchema(): void {
    try {
      $pdo = $this->getQueuePdo();
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS wsc_events_queue (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          created_at INTEGER NOT NULL,
          event_type TEXT NOT NULL,
          url_path TEXT NOT NULL,
          referrer TEXT,
          payload_json TEXT NOT NULL,
          synced INTEGER DEFAULT 0
        );
        CREATE INDEX IF NOT EXISTS idx_wsc_queue_synced ON wsc_events_queue (synced, created_at);
      ");
    }
    catch (Exception $e) {
      $this->logger->error('Failed to initialize WSC Queue SQLite: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Initialize Reports table schema.
   */
  public function initReportsSchema(): void {
    try {
      $pdo = $this->getReportsPdo();
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS wsc_dashboard_reports (
          id TEXT PRIMARY KEY,
          report_type TEXT NOT NULL,
          period_type TEXT NOT NULL,
          updated_at INTEGER NOT NULL,
          payload TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS wsc_daily_overview (
          date TEXT PRIMARY KEY,
          human_visitors INTEGER DEFAULT 0,
          human_pageviews INTEGER DEFAULT 0,
          bot_hits INTEGER DEFAULT 0,
          ai_training_hits INTEGER DEFAULT 0,
          ai_rag_hits INTEGER DEFAULT 0,
          interaction_events INTEGER DEFAULT 0,
          human_pct REAL DEFAULT 100.0
        );

        CREATE TABLE IF NOT EXISTS wsc_bot_stats (
          date TEXT NOT NULL,
          bot_name TEXT NOT NULL,
          bot_category TEXT NOT NULL,
          bot_type TEXT NOT NULL,
          hits INTEGER DEFAULT 1,
          blocked INTEGER DEFAULT 0,
          PRIMARY KEY (date, bot_name)
        );

        CREATE TABLE IF NOT EXISTS wsc_top_pages (
          date TEXT NOT NULL,
          url_path TEXT NOT NULL,
          pageviews INTEGER DEFAULT 1,
          unique_visitors INTEGER DEFAULT 1,
          PRIMARY KEY (date, url_path)
        );
      ");
    }
    catch (Exception $e) {
      $this->logger->error('Failed to initialize WSC Reports SQLite: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  /**
   * Register 'wsc_reports' database target with Drupal Database API so Views can query it.
   */
  public function registerDrupalReportsConnection(): void {
    if (!Database::getConnectionInfo('wsc_reports')) {
      Database::addConnectionInfo('wsc_reports', 'default', [
        'driver' => 'sqlite',
        'database' => $this->getReportsDbPath(),
        'prefix' => '',
      ]);
    }
  }

  /**
   * Optimize SQLite storage files (PRAGMA optimize).
   */
  public function optimizeDatabases(): void {
    try {
      $this->getQueuePdo()->exec('PRAGMA optimize;');
      $this->getReportsPdo()->exec('PRAGMA optimize;');
    }
    catch (Exception $e) {
      $this->logger->warning('WSC SQLite optimize warning: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  protected function initStorageDirectory(): void {
    $this->getStorageDir();
  }
}
