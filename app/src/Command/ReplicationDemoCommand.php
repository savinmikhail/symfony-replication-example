<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Product;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:replication:demo',
    description: 'Demonstrate sync/async replicas with optional read-your-writes routing',
)]
class ReplicationDemoCommand extends Command
{
    private const DEFAULT_AWAIT_SECONDS = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $readBalancerDsn,
        private readonly string $readSyncDsn,
        private readonly string $readAsyncDsn,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('read-your-writes', null, InputOption::VALUE_NONE, 'Route reads to sync replica')
            ->addOption('reads', null, InputOption::VALUE_OPTIONAL, 'Number of read attempts', 4)
            ->addOption('delay', null, InputOption::VALUE_OPTIONAL, 'Delay between reads in ms', 400)
            ->addOption('show-lsn', null, InputOption::VALUE_NONE, 'Show LSN diff between primary and replica')
            ->addOption('await-async', null, InputOption::VALUE_NONE, 'After initial reads, poll async replica until it catches up')
            ->addOption('await-async-seconds', null, InputOption::VALUE_OPTIONAL, 'Max seconds to wait for async replica', self::DEFAULT_AWAIT_SECONDS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $reads = max(1, (int) $input->getOption('reads'));
        $delayMs = max(0, (int) $input->getOption('delay'));
        $sticky = (bool) $input->getOption('read-your-writes');
        $showLsn = (bool) $input->getOption('show-lsn');
        $awaitAsync = (bool) $input->getOption('await-async');
        $awaitSeconds = max(1, (int) $input->getOption('await-async-seconds'));

        if (!$this->validateConfiguration($io, $awaitAsync)) {
            return Command::FAILURE;
        }

        $product = $this->createProduct();
        $productId = $product->getId();
        if ($productId === null) {
            $io->error('Failed to create product.');

            return Command::FAILURE;
        }

        $this->renderWriteSection($io, $product);

        $primaryLsn = $this->fetchPrimaryLsn($showLsn);

        $this->renderReadSection($io, $sticky);
        $this->renderReadTable($io, $reads, $delayMs, $sticky, $showLsn, $primaryLsn, $productId);

        if ($awaitAsync) {
            $this->renderAsyncFollowUp($io, $delayMs, $showLsn, $primaryLsn, $productId, $awaitSeconds);
        }

        return Command::SUCCESS;
    }

    private function validateConfiguration(SymfonyStyle $io, bool $awaitAsync): bool
    {
        if ($this->readBalancerDsn === '' || $this->readSyncDsn === '') {
            $io->error('DATABASE_URL_READ_BALANCER and DATABASE_URL_READ_SYNC must be set.');

            return false;
        }
        if ($awaitAsync && $this->readAsyncDsn === '') {
            $io->error('DATABASE_URL_READ_ASYNC must be set for --await-async.');

            return false;
        }

        return true;
    }

    private function createProduct(): Product
    {
        $product = new Product(
            'Demo ' . bin2hex(random_bytes(3)),
            number_format(random_int(10_00, 99_99) / 100, 2, '.', '')
        );

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    private function renderWriteSection(SymfonyStyle $io, Product $product): void
    {
        $io->section('Write');
        $io->writeln(sprintf(
            '<fg=green>Created product id=%d name=%s price=%s</>',
            $product->getId(),
            $product->getName(),
            $product->getPrice()
        ));
    }

    private function fetchPrimaryLsn(bool $showLsn): ?string
    {
        if (!$showLsn) {
            return null;
        }

        return (string) $this->entityManager->getConnection()->fetchOne('SELECT pg_current_wal_lsn()');
    }

    private function renderReadSection(SymfonyStyle $io, bool $sticky): void
    {
        $io->section($sticky ? '<fg=green>Read (read-your-writes -> sync replica)</>' : '<fg=green>Read (balancer: sync + async)</>');
    }

    private function renderReadTable(
        SymfonyStyle $io,
        int $reads,
        int $delayMs,
        bool $sticky,
        bool $showLsn,
        ?string $primaryLsn,
        int $productId,
    ): void {
        $rows = [];
        for ($i = 1; $i <= $reads; $i++) {
            $dsn = $sticky ? $this->readSyncDsn : $this->readBalancerDsn;
            $state = $this->fetchReplicaState($dsn, $productId, $showLsn, $primaryLsn);

            $rows[] = $this->buildReadRow($i, $reads, $state, $showLsn);

            if ($delayMs > 0 && $i < $reads) {
                usleep($delayMs * 1000);
            }
        }

        $headers = ['attempt', 'node', 'replica', 'found'];
        if ($showLsn) {
            $headers[] = 'lsn_lag_bytes';
        }

        $io->table($headers, $rows);
    }

    private function renderAsyncFollowUp(
        SymfonyStyle $io,
        int $delayMs,
        bool $showLsn,
        ?string $primaryLsn,
        int $productId,
        int $awaitSeconds,
    ): void {
        $io->section('<fg=green>Async follow-up (eventual consistency)</>');

        $followRows = [];
        $start = microtime(true);
        $attempt = 1;
        $foundEventually = false;

        while ((microtime(true) - $start) <= $awaitSeconds) {
            $state = $this->fetchReplicaState($this->readAsyncDsn, $productId, $showLsn, $primaryLsn);
            $elapsedMs = (int) ((microtime(true) - $start) * 1000);
            $followRows[] = $this->buildAsyncRow($attempt, $elapsedMs, $state, $showLsn);

            if ($state['found']) {
                $foundEventually = true;
                break;
            }

            $attempt++;
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $followHeaders = ['attempt', 'elapsed', 'node', 'found'];
        if ($showLsn) {
            $followHeaders[] = 'lsn_lag_bytes';
        }
        $io->table($followHeaders, $followRows);

        if (!$foundEventually) {
            $io->warning(sprintf('Async replica did not catch up within %d seconds.', $awaitSeconds));
        }
    }

    /**
     * @return array{node:string, replica:string, found:bool, lag:string|null}
     */
    private function fetchReplicaState(string $dsn, int $productId, bool $showLsn, ?string $primaryLsn): array
    {
        $readConn = DriverManager::getConnection($this->buildConnectionParams($dsn));
        $node = $readConn->fetchAssociative("SELECT current_setting('cluster_name') AS node, pg_is_in_recovery() AS is_replica");
        $found = (bool) $readConn->fetchOne('SELECT 1 FROM product WHERE id = ?', [$productId]);

        $lag = null;
        if ($showLsn && $primaryLsn !== null) {
            $lag = $readConn->fetchOne(
                "SELECT CASE WHEN pg_is_in_recovery() THEN pg_wal_lsn_diff(:primary_lsn, pg_last_wal_replay_lsn()) ELSE 0 END",
                ['primary_lsn' => $primaryLsn]
            );
        }

        $readConn->close();

        return [
            'node' => $node['node'] ?? 'unknown',
            'replica' => isset($node['is_replica']) ? ($node['is_replica'] ? 'yes' : 'no') : 'unknown',
            'found' => $found,
            'lag' => $lag !== null ? (string) $lag : null,
        ];
    }

    /**
     * @param array{node:string, replica:string, found:bool, lag:string|null} $state
     * @return array<int, string>
     */
    private function buildReadRow(int $attempt, int $reads, array $state, bool $showLsn): array
    {
        return [
            sprintf('%d/%d', $attempt, $reads),
            $state['node'],
            $state['replica'],
            $state['found'] ? '<fg=green>yes</>' : '<fg=red>no</>',
            $showLsn ? ($state['lag'] ?? '0') : '-',
        ];
    }

    /**
     * @param array{node:string, replica:string, found:bool, lag:string|null} $state
     * @return array<int, string>
     */
    private function buildAsyncRow(int $attempt, int $elapsedMs, array $state, bool $showLsn): array
    {
        return [
            (string) $attempt,
            $elapsedMs . 'ms',
            $state['node'],
            $state['found'] ? '<fg=green>yes</>' : '<fg=red>no</>',
            $showLsn ? ($state['lag'] ?? '0') : '-',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConnectionParams(string $dsn): array
    {
        $parts = parse_url($dsn);
        if ($parts === false) {
            throw new \InvalidArgumentException('Invalid DSN provided.');
        }

        $params = [
            'driver' => 'pdo_pgsql',
        ];

        if (!empty($parts['host'])) {
            $params['host'] = $parts['host'];
        }
        if (!empty($parts['port'])) {
            $params['port'] = $parts['port'];
        }
        if (!empty($parts['user'])) {
            $params['user'] = $parts['user'];
        }
        if (!empty($parts['pass'])) {
            $params['password'] = $parts['pass'];
        }
        if (!empty($parts['path'])) {
            $dbname = ltrim($parts['path'], '/');
            if ($dbname !== '') {
                $params['dbname'] = $dbname;
            }
        }

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (isset($query['serverVersion'])) {
                $params['serverVersion'] = $query['serverVersion'];
            }
            if (isset($query['charset'])) {
                $params['charset'] = $query['charset'];
            }
        }

        return $params;
    }
}
