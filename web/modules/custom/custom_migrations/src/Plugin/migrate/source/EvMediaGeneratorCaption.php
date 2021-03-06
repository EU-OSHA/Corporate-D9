<?php

namespace Drupal\custom_migrations\Plugin\migrate\source;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_drupal\Plugin\migrate\source\d7\FieldableEntity;

/**
 * Fiel collection to media from database.
 *
 * @todo Support field type collection to media.
 *
 * @MigrateSource(
 *   id = "ev_media_generator_caption",
 *   source_module = "file"
 * )
 */
class EvMediaGeneratorCaption extends FieldableEntity{

  /**
   * @var array
   */
  protected $sourceFields = [];

  const JOIN = 'n.vid = nr.vid';

  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, StateInterface $state, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state, $entity_type_manager);
    foreach ($this->configuration['field_names'] as $name) {
      $this->sourceFields[$name] = $name;
    }
    // Do not joint source tables.
    $this->configuration['ignore_map'] = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'target_id' => [
        'type' => 'integer',
      ],
    ];
  }

  public function count($refresh = FALSE) {
    return $this->initializeIterator()->count();
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'target_id' => $this->t('The file entity ID.'),
      'file_id' => $this->t('The file entity ID.'),
      'file_path' => $this->t('The file path.'),
      'file_name' => $this->t('The file name.'),
      'file_alt' => $this->t('The file arl.'),
      'file_title' => $this->t('The file title.'),
      'caption' => $this->t('The file caption.'),
    ];
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Select node in its last revision.
    $query = $this->select('node_revision', 'nr')
      ->fields(
        'n',
        [
          'nid',
          'type',
          'language',
          'status',
          'created',
          'changed',
          'comment',
          'promote',
          'sticky',
          'tnid',
          'translate',
        ]
      )
      ->fields(
        'nr',
        [
          'vid',
          'title',
          'log',
          'timestamp',
        ]
      );
    $query->addField('n', 'uid', 'node_uid');
    $query->addField('nr', 'uid', 'revision_uid');
    $query->innerJoin('node', 'n', static::JOIN);

    // If the content_translation module is enabled, get the source langcode
    // to fill the content_translation_source field.
    //if ($this->moduleHandler->moduleExists('content_translation')) {
      $query->leftJoin('node', 'nt', 'n.tnid = nt.nid');
      $query->addField('nt', 'language', 'source_langcode');
    //}
    //$this->handleTranslations($query);

    if (isset($this->configuration['bundle'])) {
      $query->condition('n.type', $this->configuration['bundle']);
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    $query_files = $this->select('file_managed', 'f')
      ->fields('f')
      ->condition('uri', 'temporary://%', 'NOT LIKE')
      ->orderBy('f.timestamp');

    $all_files = $query_files->execute()->fetchAllAssoc('fid');

    $files_found = [];

    foreach ($this->sourceFields as $name => $source_field) {

      $parent_iterator = parent::initializeIterator();

      foreach ($parent_iterator as $entity) {
        $nid = $entity['nid'];
        $vid = $entity['vid'];
        $langcode = !empty($this->configuration['langcode']) ? $this->configuration['langcode'] : NULL;
        $field_value = $this->getFieldValues('node', $name, $nid, $vid, $langcode);

        foreach ($field_value as $reference) {

          // Support remote file urls.
          $file_url = $all_files[$reference['fid']]['uri'];
          if (!empty($this->configuration['d7_file_url'])) {
            $file_url = str_replace('public://', '', $file_url);
            $file_path = UrlHelper::encodePath($file_url);
            $file_url = $this->configuration['d7_file_url'] . $file_path;
          }

          if (!empty($all_files[$reference['fid']]['uri'])) {

            // Get the caption from D7 ddbb
            $caption_text = $this->select('field_image_field_caption', 'cap')
              ->fields('cap', ['caption'])
              ->condition('entity_id,', $entity['nid'])
              ->execute()
              ->fetchCol();
            if (!empty($caption_text[0])) {
              $caption = $caption_text[0];
            }else{
              $caption = "";
            }

            $files_found[] = [
              'nid' => $entity['nid'],
              'target_id' => $reference['fid'],
              'alt' => isset($reference['alt']) ? $reference['alt'] : NULL,
              'title' => isset($reference['title']) ? $reference['title'] : NULL,
              'display' => isset($reference['display']) ? $reference['display'] : NULL,
              'description' => isset($reference['description']) ? $reference['description'] : NULL,
              'langcode' => $this->configuration['langcode'],
              'entity' => $entity,
              'file_name' => $all_files[$reference['fid']]['filename'],
              'file_path' => $file_url,
              'caption' => $caption,
            ];
          }
        }
      }
    }
    return new \ArrayIterator($files_found);
  }

}
