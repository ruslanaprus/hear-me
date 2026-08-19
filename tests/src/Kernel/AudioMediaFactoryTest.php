<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Language\LanguageInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\Entity\File;
use Drupal\hear_me\Service\AudioMediaFactory;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\media\Entity\Media;
use Drupal\media\Entity\MediaType;
use Drupal\media\MediaInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests persistent File and Media creation for generated audio.
 */
#[Group('hear_me')]
#[RunTestsInSeparateProcesses]
class AudioMediaFactoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'language',
    'media',
    'hear_me',
    'hear_me_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['hear_me']);
  }

  /**
   * Tests new File and Media creation, language, and naming behavior.
   */
  public function testCreatesFileAndNamedMedia(): void {
    $uri = 'public://tts/new-audio.wav';
    $text = 'Factory naming text';
    ConfigurableLanguage::createFromLangcode('uk')->save();

    $media = $this->createMediaFromUri($uri, 'uk', $text);

    $this->assertInstanceOf(MediaInterface::class, $media);
    $this->assertSame('hear_me_audio', $media->bundle());
    $this->assertSame('uk', $media->language()->getId());
    $this->assertSame('TTS-uk-' . md5($text), $media->label());
    $file = $media->get('field_hear_me_audio_file')->entity;
    $this->assertInstanceOf(File::class, $file);
    $this->assertSame($uri, $file->getFileUri());
    $this->assertTrue($file->isPermanent());
    $this->assertSame(1, $this->countEntities('file', ['uri' => $uri]));
    $this->assertSame(1, $this->countEntities('media'));
  }

  /**
   * Tests unknown synthesis languages use language-neutral Media metadata.
   */
  public function testUnknownSynthesisLanguageUsesUnd(): void {
    $media = $this->createMediaFromUri(
      'public://tts/provider-language.wav',
      'provider-voice',
      'Provider language',
    );

    $this->assertSame(LanguageInterface::LANGCODE_NOT_SPECIFIED, $media->language()->getId());
    $this->assertSame('TTS-provider-voice-' . md5('Provider language'), $media->label());
  }

  /**
   * Tests provider language syntax is normalized for Drupal metadata.
   */
  public function testNormalizesInstalledSynthesisLanguage(): void {
    ConfigurableLanguage::createFromLangcode('en-us')->save();

    $media = $this->createMediaFromUri(
      'public://tts/regional-language.wav',
      'EN_US',
      'Regional language',
    );

    $this->assertSame('en-us', $media->language()->getId());
    $this->assertSame('TTS-EN_US-' . md5('Regional language'), $media->label());
  }

  /**
   * Tests an existing managed File is reused.
   */
  public function testReusesExistingFile(): void {
    $uri = 'public://tts/existing-file.wav';
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();

    $media = $this->createMediaFromUri($uri, 'en', 'Existing File');

    $this->assertSame((int) $file->id(), (int) $media->get('field_hear_me_audio_file')->target_id);
    $this->assertSame(1, $this->countEntities('file', ['uri' => $uri]));
    $this->assertSame(1, $this->countEntities('media'));
  }

  /**
   * Tests existing Media for the managed File is reused unchanged.
   */
  public function testReusesExistingMedia(): void {
    $uri = 'public://tts/existing-media.wav';
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $existing = Media::create([
      'bundle' => 'hear_me_audio',
      'name' => 'Existing media name',
      'field_hear_me_audio_file' => ['target_id' => $file->id()],
    ]);
    $existing->save();

    $media = $this->createMediaFromUri($uri, 'fr', 'Different text');

    $this->assertSame((int) $existing->id(), (int) $media->id());
    $this->assertSame('Existing media name', $media->label());
    $this->assertSame('en', $media->language()->getId());
    $this->assertSame(1, $this->countEntities('file', ['uri' => $uri]));
    $this->assertSame(1, $this->countEntities('media'));
  }

  /**
   * Tests Media from another bundle does not satisfy HearMe Media reuse.
   */
  public function testIgnoresExistingMediaFromAnotherBundle(): void {
    MediaType::create([
      'id' => 'other_audio',
      'label' => 'Other audio',
      'source' => 'audio_file',
      'source_configuration' => [
        'source_field' => 'field_hear_me_audio_file',
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_hear_me_audio_file',
      'entity_type' => 'media',
      'bundle' => 'other_audio',
      'label' => 'Audio File',
      'required' => TRUE,
      'settings' => [
        'handler' => 'default:file',
        'file_directory' => 'tts',
        'file_extensions' => 'wav mp3 ogg',
      ],
    ])->save();
    $uri = 'public://tts/shared-bundle-file.wav';
    $file = File::create(['uri' => $uri, 'status' => 1]);
    $file->save();
    $foreignMedia = Media::create([
      'bundle' => 'other_audio',
      'name' => 'Foreign media',
      'field_hear_me_audio_file' => ['target_id' => $file->id()],
    ]);
    $foreignMedia->save();

    $media = $this->createMediaFromUri($uri, 'en', 'HearMe media');

    $this->assertNotSame((int) $foreignMedia->id(), (int) $media->id());
    $this->assertSame('hear_me_audio', $media->bundle());
    $this->assertSame((int) $file->id(), (int) $media->get('field_hear_me_audio_file')->target_id);
    $this->assertSame(2, $this->countEntities('media'));
  }

  /**
   * Tests a File persistence failure propagates without creating entities.
   */
  public function testFilePersistenceFailurePropagates(): void {
    $this->container->get('state')->set('hear_me_test.fail_file_save', TRUE);

    try {
      $this->createMediaFromUri('public://tts/file-failure.wav', 'en', 'File failure');
      $this->fail('Expected the File save exception to propagate.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('Simulated File save failure.', $exception->getPrevious()?->getMessage());
    }

    $this->assertSame(0, $this->countEntities('file', ['uri' => 'public://tts/file-failure.wav']));
    $this->assertSame(0, $this->countEntities('media'));
  }

  /**
   * Tests a Media persistence failure propagates after File persistence.
   */
  public function testMediaPersistenceFailurePropagates(): void {
    $this->container->get('state')->set('hear_me_test.fail_media_save', TRUE);

    try {
      $this->createMediaFromUri('public://tts/media-failure.wav', 'en', 'Media failure');
      $this->fail('Expected the Media save exception to propagate.');
    }
    catch (EntityStorageException $exception) {
      $this->assertSame('Simulated Media save failure.', $exception->getPrevious()?->getMessage());
    }

    $this->assertSame(1, $this->countEntities('file', ['uri' => 'public://tts/media-failure.wav']));
    $this->assertSame(0, $this->countEntities('media'));
  }

  /**
   * Creates Media through the focused factory service.
   */
  private function createMediaFromUri(string $uri, string $lang, string $text): MediaInterface {
    $factory = $this->container->get('hear_me.audio_media_factory');
    $this->assertInstanceOf(AudioMediaFactory::class, $factory);
    $media = $factory->createFromUri($uri, $lang, $text);
    $this->assertInstanceOf(MediaInterface::class, $media);
    return $media;
  }

  /**
   * Counts stored entities without access checks.
   */
  private function countEntities(string $entityTypeId, array $conditions = []): int {
    $query = $this->container->get('entity_type.manager')
      ->getStorage($entityTypeId)
      ->getQuery()
      ->accessCheck(FALSE);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    return (int) $query->count()->execute();
  }

}
