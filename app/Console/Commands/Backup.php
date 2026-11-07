<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Backups and restore tests (Target 92-93, 186; LMS 139, 211, 238-239).
 *
 *   backup:run           database dump + private files (uploads, evidence, submissions), gzip,
 *                        optional AES-256-GCM encryption, SHA-256 recorded, old backups pruned
 *   backup:restore-test  restores the newest dump into a scratch database ON THE SAME LOCAL
 *                        SERVER, compares row counts with the live tables, then drops it
 *
 * Like legacy:import, this only ever talks to a database on this computer.
 */
class Backup extends Command
{
    protected $signature = 'backup:run {--restore-test : Restore the newest backup into a scratch database and compare}';

    protected $description = 'Back up the database and private files (or test-restore the newest backup)';

    private const LOCAL = ['localhost', '127.0.0.1', '::1'];

    private const CHECK_TABLES = ['users', 'questions', 'exams', 'attempts', 'attempt_answers', 'results', 'audit_logs', 'enrolments', 'course_items', 'assignment_submissions'];

    public function handle(): int
    {
        $conn = config('database.connections.'.config('database.default'));
        if (in_array($conn['driver'], ['mysql', 'mariadb'], true) && ! in_array(strtolower((string) $conn['host']), self::LOCAL, true)) {
            $this->error('Backups only run against a database on this computer.');

            return self::FAILURE;
        }

        return $this->option('restore-test') ? $this->restoreTest($conn) : $this->backup($conn);
    }

    private function backup(array $conn): int
    {
        $dir = rtrim(config('platform.backup.path'), '/\\');
        @mkdir($dir, 0700, true);
        $stamp = now()->format('Ymd-His');
        try {
            $db = "$dir/db-$stamp.sql.gz";
            if ($conn['driver'] === 'sqlite') {
                $this->gzipFile($conn['database'], $db);
            } else {
                $this->dump($conn, $db);
            }
            $files = "$dir/files-$stamp.zip";
            $this->zipPrivateFiles($files);

            $out = [];
            foreach ([$db, $files] as $path) {
                if (($pass = config('platform.backup.passphrase')) !== '') {
                    $this->encrypt($path, "$path.enc", $pass);
                    unlink($path);
                    $path .= '.enc';
                }
                @chmod($path, 0600);
                $out[] = $path;
                DB::table('backup_runs')->insert(['kind' => 'backup', 'status' => 'ok', 'path' => $path, 'bytes' => filesize($path),
                    'sha256' => hash_file('sha256', $path), 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->prune($dir);
            $this->info('Backup written: '.implode(', ', array_map('basename', $out)));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::table('backup_runs')->insert(['kind' => 'backup', 'status' => 'failed', 'message' => substr($e->getMessage(), 0, 1000), 'created_at' => now(), 'updated_at' => now()]);
            $this->error('Backup failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
    }

    private function dump(array $c, string $target): void
    {
        $bin = env('BACKUP_MYSQLDUMP', 'mysqldump');
        // Password through the environment, never on the command line
        $p = new Process([$bin, '--single-transaction', '--quick', '--routines', '--triggers', '--no-tablespaces', '--default-character-set=utf8mb4',
            '-h', $c['host'], '-P', (string) $c['port'], '-u', $c['username'], $c['database']], null, ['MYSQL_PWD' => (string) $c['password']], null, 3600);
        $gz = gzopen($target, 'wb6');
        $p->run(function ($type, $buffer) use ($gz) {
            if ($type === Process::OUT) {
                gzwrite($gz, $buffer);
            }
        });
        gzclose($gz);
        if (! $p->isSuccessful()) {
            @unlink($target);
            throw new \RuntimeException(trim($p->getErrorOutput()) ?: 'mysqldump failed (set BACKUP_MYSQLDUMP to its full path, e.g. C:\\xampp\\mysql\\bin\\mysqldump.exe)');
        }
    }

    private function zipPrivateFiles(string $target): void
    {
        $root = storage_path('app/private');
        $zip = new \ZipArchive;
        $zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if (is_dir($root)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $rel = substr($file->getPathname(), strlen($root) + 1);
                if ($file->isFile() && ! str_starts_with($rel, 'backups')) {
                    $zip->addFile($file->getPathname(), str_replace('\\', '/', $rel));
                }
            }
        }
        $zip->addFromString('README.txt', 'Private files (question media, evidence, submissions). Restore into storage/app/private.');
        $zip->close();
    }

    /** Chunked AES-256-GCM; each 1 MB chunk authenticated with its index, so truncation or reordering is detected. */
    private function encrypt(string $in, string $out, string $pass): void
    {
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $pass, $salt, 200000, 32, true);
        $r = fopen($in, 'rb');
        $w = fopen($out, 'wb');
        fwrite($w, "VBK1$salt");
        $i = 0;
        while (! feof($r)) {
            $chunk = fread($r, 1048576);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $iv = random_bytes(12);
            $tag = '';
            $ct = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, pack('J', $i++));
            fwrite($w, pack('N', strlen($ct)).$iv.$tag.$ct);
        }
        fwrite($w, pack('N', 0).pack('J', $i)); // end marker with chunk count
        fclose($r);
        fclose($w);
    }

    public function decrypt(string $in, string $out, string $pass): void
    {
        $r = fopen($in, 'rb');
        if (fread($r, 4) !== 'VBK1') {
            throw new \RuntimeException('Not a backup file made by this platform.');
        }
        $key = hash_pbkdf2('sha256', $pass, fread($r, 16), 200000, 32, true);
        $w = fopen($out, 'wb');
        $i = 0;
        while (true) {
            $len = unpack('N', fread($r, 4))[1];
            if ($len === 0) {
                if (unpack('J', fread($r, 8))[1] !== $i) {
                    throw new \RuntimeException('Backup is truncated.');
                }
                break;
            }
            $iv = fread($r, 12);
            $tag = fread($r, 16);
            $pt = openssl_decrypt(fread($r, $len), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, pack('J', $i++));
            if ($pt === false) {
                throw new \RuntimeException('Backup failed its integrity check (wrong passphrase or damaged file).');
            }
            fwrite($w, $pt);
        }
        fclose($r);
        fclose($w);
    }

    private function restoreTest(array $c): int
    {
        $run = DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->where('path', 'like', '%db-%')->latest('id')->first();
        if (! $run || ! is_file($run->path)) {
            $this->error('No backup found to test.');

            return self::FAILURE;
        }
        try {
            if (hash_file('sha256', $run->path) !== $run->sha256) {
                throw new \RuntimeException('Checksum does not match what was recorded at backup time.');
            }
            $sql = $run->path;
            $tmp = null;
            if (str_ends_with($sql, '.enc')) {
                $tmp = $sql = tempnam(sys_get_temp_dir(), 'vbk');
                $this->decrypt($run->path, $tmp, config('platform.backup.passphrase'));
            }
            $live = collect(self::CHECK_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

            if ($c['driver'] === 'sqlite') {
                $restored = $this->restoreSqlite($sql);
            } else {
                $restored = $this->restoreMysql($c, $sql);
            }
            $tmp && @unlink($tmp);

            $diff = $live->map(fn ($n, $t) => ['table' => $t, 'live' => $n, 'restored' => $restored[$t] ?? null])->values();
            $missing = $diff->filter(fn ($d) => $d['restored'] === null || $d['restored'] > $d['live'] || $d['restored'] < $d['live'] * 0.5);
            $this->table(['table', 'live', 'restored'], $diff->all());
            $status = $missing->isEmpty() ? 'ok' : 'failed';
            DB::table('backup_runs')->insert(['kind' => 'restore_test', 'status' => $status, 'path' => $run->path,
                'message' => json_encode($diff), 'created_at' => now(), 'updated_at' => now()]);
            $status === 'ok' ? $this->info('Restore test passed.') : $this->error('Restore test found differences.');

            return $status === 'ok' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            DB::table('backup_runs')->insert(['kind' => 'restore_test', 'status' => 'failed', 'path' => $run->path, 'message' => substr($e->getMessage(), 0, 1000), 'created_at' => now(), 'updated_at' => now()]);
            $this->error('Restore test failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function restoreMysql(array $c, string $gz): array
    {
        $scratch = $c['database'].'_restore_test';
        DB::statement("DROP DATABASE IF EXISTS `$scratch`");
        DB::statement("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $bin = env('BACKUP_MYSQL', 'mysql');
            $p = new Process([$bin, '-h', $c['host'], '-P', (string) $c['port'], '-u', $c['username'], $scratch], null, ['MYSQL_PWD' => (string) $c['password']], null, 3600);
            $in = new \Symfony\Component\Process\InputStream;
            $p->setInput($in);
            $p->start();
            $h = gzopen($gz, 'rb');
            while (! gzeof($h)) {
                $in->write(gzread($h, 1048576));
            }
            gzclose($h);
            $in->close();
            $p->wait();
            if (! $p->isSuccessful()) {
                throw new \RuntimeException(trim($p->getErrorOutput()) ?: 'mysql import failed');
            }

            return collect(self::CHECK_TABLES)->mapWithKeys(fn ($t) => [$t => (int) DB::selectOne("SELECT COUNT(*) n FROM `$scratch`.`$t`")->n])->all();
        } finally {
            DB::statement("DROP DATABASE IF EXISTS `$scratch`");
        }
    }

    private function restoreSqlite(string $gz): array
    {
        $file = tempnam(sys_get_temp_dir(), 'vrt');
        $h = gzopen($gz, 'rb');
        file_put_contents($file, '');
        while (! gzeof($h)) {
            file_put_contents($file, gzread($h, 1048576), FILE_APPEND);
        }
        gzclose($h);
        $pdo = new \PDO("sqlite:$file");
        $out = collect(self::CHECK_TABLES)->mapWithKeys(fn ($t) => [$t => (int) $pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn()])->all();
        unset($pdo);
        @unlink($file);

        return $out;
    }

    private function gzipFile(string $from, string $to): void
    {
        $r = fopen($from, 'rb');
        $w = gzopen($to, 'wb6');
        while (! feof($r)) {
            gzwrite($w, fread($r, 1048576));
        }
        fclose($r);
        gzclose($w);
    }

    private function prune(string $dir): void
    {
        $cutoff = now()->subDays(config('platform.backup.keep_days'))->getTimestamp();
        foreach (array_merge(glob("$dir/db-*") ?: [], glob("$dir/files-*") ?: []) as $f) {
            if (filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }
}
