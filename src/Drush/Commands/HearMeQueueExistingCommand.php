<?php

namespace Drupal\hear_me\Drush\Commands;

use Drupal\hear_me\Service\HearMeExistingContentQueue;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Queues existing content for HearMe audio pre-generation.
 */
#[AsCommand(
  name: 'hear-me:queue-existing',
  description: 'Queues published existing content for HearMe audio generation.',
  aliases: ['hear-me-backfill'],
)]
final class HearMeQueueExistingCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly HearMeExistingContentQueue $existingContentQueue,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->addOption('bundles', NULL, InputOption::VALUE_REQUIRED, 'Comma-separated configured content type machine names.', '')
      ->addOption('requeue-existing', NULL, InputOption::VALUE_NONE, 'Queue nodes even when the configured audio field already has media.')
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Maximum candidate nodes to scan; zero means no limit.', '0')
      ->addUsage('hear-me:queue-existing')
      ->addUsage('hear-me:queue-existing --bundles=article,page --limit=5000')
      ->addUsage('hear-me:queue-existing --requeue-existing');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $io = new SymfonyStyle($input, $output);
    $requestedBundles = $this->parseBundles((string) $input->getOption('bundles'));
    $configuredBundles = $this->existingContentQueue->getConfiguredBundles();
    if (!$configuredBundles) {
      throw new \InvalidArgumentException('No content types are configured for HearMe queue pre-generation. Configure queue bundles first.');
    }

    $unconfiguredBundles = $this->existingContentQueue->getUnconfiguredBundles($requestedBundles);
    if ($unconfiguredBundles) {
      $io->warning(sprintf(
        'Ignoring bundle(s) that are not configured for HearMe queue pre-generation: %s',
        implode(', ', $unconfiguredBundles),
      ));
    }

    $bundles = $this->existingContentQueue->filterConfiguredBundles($requestedBundles);
    if (!$bundles) {
      throw new \InvalidArgumentException('No requested content types are configured for HearMe queue pre-generation.');
    }

    $stats = $this->existingContentQueue->queueAll(
      $bundles,
      TRUE,
      !$input->getOption('requeue-existing'),
      max(0, (int) $input->getOption('limit')),
      HearMeExistingContentQueue::DEFAULT_BATCH_SIZE,
    );

    $io->success(sprintf(
      'Queued %d HearMe audio job(s) after scanning %d node(s).',
      $stats['queued'],
      $stats['scanned'],
    ));

    $io->table(['Metric', 'Count'], [
      ['Scanned', $stats['scanned']],
      ['Queued', $stats['queued']],
      ['Failed publication attempts', $stats['failed_queue_publication']],
      ['Skipped: already queued', $stats['skipped_duplicate_queue']],
      ['Skipped: already had audio', $stats['skipped_existing_audio']],
      ['Skipped: missing audio field', $stats['skipped_field_missing']],
      ['Skipped: no source text', $stats['skipped_source_empty']],
      ['Skipped: unsupported language', $stats['skipped_unsupported_language']],
      ['Skipped: could not be loaded', $stats['skipped_not_loaded']],
    ]);

    return Command::SUCCESS;
  }

  /**
   * Parses comma-separated bundle option values.
   *
   * @return string[]
   *   Bundle machine names.
   */
  protected function parseBundles(string $bundles): array {
    if ($bundles === '') {
      return [];
    }

    return array_values(array_unique(array_filter(array_map(
      static fn($bundle) => trim($bundle),
      explode(',', $bundles),
    ))));
  }

}
