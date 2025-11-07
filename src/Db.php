<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

final class Db
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $path = $config['path'] ?? null;
        if (!$path) {
            throw new PDOException('Database path is not configured.');
        }

        $this->pdo = new PDO(
            'sqlite:' . $path,
            options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        // Enable WAL mode for better concurrency and performance
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        
        // Enable foreign key constraints
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
        
        // Performance optimizations
        $this->pdo->exec('PRAGMA synchronous = NORMAL;');
        $this->pdo->exec('PRAGMA cache_size = -64000;'); // 64MB cache
        $this->pdo->exec('PRAGMA temp_store = MEMORY;');
        $this->pdo->exec('PRAGMA mmap_size = 30000000000;'); // 30GB memory-mapped I/O
        $this->pdo->exec('PRAGMA page_size = 4096;'); // Optimal page size for modern systems
        
        // Query optimization
        $this->pdo->exec('PRAGMA optimize;'); // Analyze and optimize query planner
        
        // WAL-specific optimizations
        $this->pdo->exec('PRAGMA wal_autocheckpoint = 1000;'); // Checkpoint every 1000 pages
        $this->pdo->exec('PRAGMA wal_checkpoint(TRUNCATE);'); // Truncate WAL file
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): array|null
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $result = $stmt->fetch();
        return $result === false ? null : $result;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$this->pdo->lastInsertId();
    }

    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    public function transaction(callable $callback): mixed
    {
        try {
            $this->pdo->beginTransaction();
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
    }

    /**
     * Run ANALYZE to update query planner statistics.
     * Should be called periodically (e.g., after bulk operations or daily maintenance).
     */
    public function analyze(): void
    {
        $this->pdo->exec('ANALYZE;');
    }

    /**
     * Manually checkpoint the WAL file to reclaim space.
     * Returns the number of frames in the WAL file and the number checkpointed.
     */
    public function checkpoint(): array
    {
        $result = $this->pdo->query('PRAGMA wal_checkpoint(TRUNCATE);')->fetch();
        return [
            'busy' => (int)($result[0] ?? 0),
            'log' => (int)($result[1] ?? 0),
            'checkpointed' => (int)($result[2] ?? 0),
        ];
    }
}