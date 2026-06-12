<?php
declare(strict_types=1);

/**
 * One-shot schema migration for Aiven MySQL.
 * Operator runs: php bin/migrate.php
 * Requires .env (or shell env) with DB_HOST/PORT/NAME/USER/PASSWORD for Aiven.
 * Must NOT be run on Vercel (serverless has no shell — see D-07).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.' . PHP_EOL);
}

$root = dirname(__DIR__); // bin/ -> project root

// PSR-4 autoloader: App\ -> src/ (mirrors public/index.php exactly)
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require $root . '/src/helpers.php';

use App\Core\Env;
use App\Core\Database;
use App\Core\Schema;

Env::load($root . '/.env');

echo 'Connecting to Aiven MySQL (' . Env::get('DB_HOST', '?') . ')...' . PHP_EOL;

// Database::connection() handles TLS options + friendly error + exit on failure.
// After D-03: connection() no longer calls Schema::ensure(). This script owns that.
$pdo = Database::connection();

echo 'Connected.' . PHP_EOL;
echo 'Applying sql/schema.sql...' . PHP_EOL;

$sqlFile = $root . '/sql/schema.sql';
if (!is_file($sqlFile)) {
    echo 'ERROR: sql/schema.sql not found at ' . $sqlFile . PHP_EOL;
    exit(1);
}

$sql = (string) file_get_contents($sqlFile);

// Split by semicolon; skip CREATE DATABASE and USE statements.
// Aiven pre-creates the database; operator may not have CREATE DATABASE privilege.
// The DSN already specifies the database name, so USE is redundant.
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    static fn(string $s): bool => $s !== ''
        && !preg_match('/^\s*(CREATE\s+DATABASE\b|USE\s+\w)/i', $s)
);

$count = 0;
foreach ($statements as $stmt) {
    try {
        $pdo->exec($stmt);
        $count++;
    } catch (\PDOException $e) {
        echo 'ERROR executing statement: ' . $e->getMessage() . PHP_EOL;
        echo 'Statement:' . PHP_EOL . $stmt . PHP_EOL;
        exit(1);
    }
}

echo "schema.sql applied ({$count} statements)." . PHP_EOL;
echo 'Applying Schema::ensure() (idempotent structural additions)...' . PHP_EOL;

try {
    Schema::ensure($pdo);
} catch (\Throwable $e) {
    echo 'ERROR in Schema::ensure(): ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

echo 'Migration complete. Database is ready.' . PHP_EOL;
exit(0);
