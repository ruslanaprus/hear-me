<?php

declare(strict_types=1);

namespace Drupal\hear_me_test\Plugin\Filter;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;

/**
 * Removes test-only private markup for anonymous viewers.
 */
#[Filter(
  id: 'hear_me_test_queue_source',
  title: new TranslatableMarkup('HearMe queue source test filter'),
  type: FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
)]
final class QueueSourceTestFilter extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    if (\Drupal::state()->get('hear_me_test.throw_queue_source_filter', FALSE)) {
      throw new \RuntimeException('Sentinel filter processing failure.');
    }

    if (\Drupal::currentUser()->isAnonymous()) {
      $text = preg_replace('/<private>.*?<\/private>/su', '', $text) ?? '';
    }

    return new FilterProcessResult($text);
  }

}
