<?php

namespace Drupal\islandora_image\Plugin\Action;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\islandora\IslandoraUtils;
use Drupal\islandora\EventGenerator\EmitEvent;
use Drupal\islandora\EventGenerator\EventGeneratorInterface;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Stomp\StatefulStomp;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Emits a Node event.
 *
 * @Action(
 *   id = "generate_image_derivative",
 *   label = @Translation("Generate an image derivative"),
 *   type = "node"
 * )
 */
class GenerateImageDerivative extends EmitEvent {

  /**
   * Islandora utility functions.
   *
   * @var \Drupal\islandora\IslandoraUtils
   */
  protected $utils;

  /**
   * Media source service.
   *
   * @var \Drupal\islandora\MediaSource\MediaSourceService
   */
  protected $mediaSource;

  /**
   * Constructs a EmitEvent action.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current user.
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\islandora\EventGenerator\EventGeneratorInterface $event_generator
   *   EventGenerator service to serialize AS2 events.
   * @param \Stomp\StatefulStomp $stomp
   *   Stomp client.
   * @param \Drupal\jwt\Authentication\Provider\JwtAuth $auth
   *   JWT Auth client.
   * @param \Drupal\islandora\IslandoraUtils $utils
   *   Islandora utility functions.
   * @param \Drupal\islandora\MediaSource\MediaSourceService $media_source
   *   Media source service.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AccountInterface $account,
    EntityTypeManager $entity_type_manager,
    EventGeneratorInterface $event_generator,
    StatefulStomp $stomp,
    JwtAuth $auth,
    IslandoraUtils $utils,
    MediaSourceService $media_source
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $account,
      $entity_type_manager,
      $event_generator,
      $stomp,
      $auth
    );
    $this->utils = $utils;
    $this->mediaSource = $media_source;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('islandora.eventgenerator'),
      $container->get('islandora.stomp'),
      $container->get('jwt.authentication.jwt'),
      $container->get('islandora.utils'),
      $container->get('islandora.media_source_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'queue' => 'islandora-connector-houdini',
      'event' => 'Generate Derivative',
      'source_term_uri' => '',
      'derivative_term_uri' => '',
      'mimetype' => 'image/jpeg',
      'args' => '',
    ];
  }

  /**
   * Override this to return arbitrary data as an array to be json encoded.
   */
  protected function generateData($entity) {
    $data = parent::generateData($entity);

    // Find media belonging to node that has the source term, and set its file
    // url in the data array.
    $source_term = $this->utils->getTermForUri($this->configuration['source_term_uri']);
    $source_media = $this->utils->getMediaWithTerm($entity, $source_term);
    $source_field = $this->mediaSource->getSourceFieldName($source_media->bundle());
    $files = $source_media->get($source_field)->referencedEntities();
    $file = reset($files);
    $data['source_uri'] = $file->url('canonical', ['absolute' => TRUE]);

    // Find the term for the derivative and use it to set the destination url
    // in the data array.
    $derivative_term = $this->utils->getTermForUri($this->configuration['derivative_term_uri']);
    $route_params = [
      'node' => $entity->id(),
      'media_type' => 'image',
      'taxonomy_term' => $derivative_term->id(),
    ];
    $data['destination_uri'] = Url::fromRoute('islandora.media_source_put_to_node', $route_params)
      ->setAbsolute()
      ->toString();

    // Generate a filename for the derivative.
    $parts = explode('/', $data['mimetype']);
    $extension = $parts[1];

    $parts = explode('#', $this->configuration['derivative_term_uri']);
    if (count($parts) > 1) {
      $name = $entity->uuid() . ' - ' . $parts[1];
    }
    else {
      $name = $entity->uuid() . ' - Derivative';
    }
    $data['filename'] = "$name.$extension";

    // Get rid of some config so we just pass along
    // what islandora-connector-houdini needs.
    unset($data['source_term_uri']);
    unset($data['derivative_term_uri']);

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['event']['#disabled'] = 'disabled';

    $form['source_term'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#title' => t('Source term'),
      '#default_value' => $this->utils->getTermForUri($this->configuration['source_term_uri']),
      '#required' => TRUE,
      '#description' => t('Term indicating the source media'),
    ];
    $form['derivative_term'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#title' => t('Derivative term'),
      '#default_value' => $this->utils->getTermForUri($this->configuration['derivative_term_uri']),
      '#required' => TRUE,
      '#description' => t('Term indicating the derivative media'),
    ];
    $form['mimetype'] = [
      '#type' => 'textfield',
      '#title' => t('Mimetype'),
      '#default_value' => $this->configuration['mimetype'],
      '#required' => TRUE,
      '#rows' => '8',
      '#description' => t('Mimetype to convert to (e.g. image/jpeg, image/png, etc...)'),
    ];
    $form['args'] = [
      '#type' => 'textfield',
      '#title' => t('Additional arguments'),
      '#default_value' => $this->configuration['args'],
      '#rows' => '8',
      '#description' => t('Additional command line arguments for ImageMagick convert (e.g. -resize 50%'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $exploded_mime = explode('/', $form_state->getValue('mimetype'));

    if (count($exploded_mime) != 2) {
      $form_state->setErrorByName(
        'mimetype',
        t('Please enter an image mimetype (e.g. image/jpeg, image/png, etc...)')
      );
    }

    if ($exploded_mime[0] != "image") {
      $form_state->setErrorByName(
        'mimetype',
        t('Please enter an image mimetype (e.g. image/jpeg, image/png, etc...)')
      );
    }

    if (empty($exploded_mime[1])) {
      $form_state->setErrorByName(
        'mimetype',
        t('Please enter an image mimetype (e.g. image/jpeg, image/png, etc...)')
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $tid = $form_state->getValue('source_term');
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    $this->configuration['source_term_uri'] = $this->utils->getUriForTerm($term);

    $tid = $form_state->getValue('derivative_term');
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    $this->configuration['derivative_term_uri'] = $this->utils->getUriForTerm($term);

    $this->configuration['mimetype'] = $form_state->getValue('mimetype');
    $this->configuration['args'] = $form_state->getValue('args');
  }

}
