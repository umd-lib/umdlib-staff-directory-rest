<?php

namespace Drupal\umdlib_staff_directory_rest\impl;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\StringItem;
use Drupal\Core\Field\Plugin\Field\FieldType\IntegerItem;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\Entity\Node;
use Drupal\umdlib_staff_directory_rest\DrupalGatewayInterface;

/**
 * Drupal gateway for the UMD Staff Directory REST endpoint.
 */
class DrupalGateway implements DrupalGatewayInterface {
  /**
   * The entity type manager used to access the database.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Array of all Person nodes (both published and unpublished).
   *
   * @var array
   */
  protected $personNodes;

  /**
   * Associative array of UMD directory ids, indexed by Person node ids.
   *
   * Used to lookup Person nodes in the database, for a given
   * UMD directory id.
   *
   * @var array
   */
  protected $directoryIdToNodeIds;

  /**
   * Associatve array of Division taxonomy ids by library division name.
   *
   * @var array
   */
  protected $divisionIdsByName;


  /**
   * Mapping of Person fields to fields in the Staff Directory JSON file.
   */
  const PERSON_TO_STAFF_DIRECTORY = [
    'field_directory_id' => 'directory_id',
    'field_library_division' => 'division',
    'field_library_department' => 'department',
    'field_library_unit' => 'unit',
    'field_first_name' => 'first_name',
    'field_last_name' => 'last_name',
    'field_phone' => 'phone',
    'field_email' => 'email',
    'field_title' => 'title',
    'field_location' => 'location',
    'title' => 'display_name',
  ];

  const STATUS_PUBLISHED = 1;
  const STATUS_UNPUBLISHED = 0;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager for communicating with the database.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger instance.
   */
  public function __construct(
      EntityTypeManagerInterface $entity_type_manager,
      LoggerChannelInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;

    $this->initialize();
  }

  /**
   * Initializes the gateway instance by retrieving items from the database.
   */
  private function initialize() {
    $this->personNodes = self::personsNodes($this->entityTypeManager);
    $this->directoryIdToNodeIds = $this->getDirectoryIdsToNodeIds($this->personNodes);
    $this->divisionIdsByName = self::getDivisionIdsByName($this->entityTypeManager);
  }

  /**
   * {@inheritdoc}
   */
  public function addEntry(array $staff_dir_values) {
    $values = [
      'type' => 'person',
      'title' => $staff_dir_values['display_name'],
    ];
    $node = $this->entityTypeManager->getStorage('node')->create($values);
    $this->populateNode($node, $staff_dir_values);
    $node->save();
  }

  /**
   * {@inheritdoc}
   */
  public function updateEntry(string $directory_id, array $staff_dir_values) {
    $node_id = $this->directoryIdToNodeIds[$directory_id];
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);

    $this->populateNode($node, $staff_dir_values);
    $node->save();
  }

  /**
   * {@inheritdoc}
   */
  public function removeEntry(string $directory_id) {
    $node_id = $this->directoryIdToNodeIds[$directory_id];
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);

    $node->set('status', self::STATUS_UNPUBLISHED);
    $node->save();
  }

  /**
   * {@inheritdoc}
   */
  public function republishEntry(string $directory_id) {
    $node_id = $this->directoryIdToNodeIds[$directory_id];
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);

    $node->set('status', self::STATUS_PUBLISHED);
    $node->save();
  }

  /**
   * Populates the given nodes with the Staff Directory values in the array.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The Node to populate.
   * @param array $staff_dir_values
   *   The staff directory values to populate the node with.
   */
  private function populateNode(Node $node, array $staff_dir_values) {
    $field_mappings = array_flip(self::PERSON_TO_STAFF_DIRECTORY);

    foreach ($field_mappings as $staff_dir_field => $umdlib_field) {
      if ($staff_dir_field == "division") {
        $this->setDivision($node, $staff_dir_values, $staff_dir_field, $field_mappings[$staff_dir_field]);
        continue;
      }

      $node->set($umdlib_field, $staff_dir_values[$staff_dir_field]);
    }

    // Ensure node is published.
    $node->set('status', self::STATUS_PUBLISHED);
  }

  /**
   * Sets the Library Division on the given node.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The Node to set the Divison on.
   * @param array $staff_dir_values
   *   The associative array containing the Staff Directory values, with the
   *   library division as a simple human-readable string.
   * @param string $staff_dir_field
   *   The key in the $staff_dir_values associative map representing the library
   *   division.
   * @param string $umdlib_person_field
   *   The Person field that should be updated with the library division.
   */
  private function setDivision(Node $node, array $staff_dir_values, string $staff_dir_field, string $umdlib_person_field) {
    $division_name = $staff_dir_values[$staff_dir_field];
    if (!empty($division_name)) {
      if (array_key_exists($division_name, $this->divisionIdsByName)) {
        $division_id = $this->divisionIdsByName[$division_name];
        $node->set($umdlib_person_field, ['target_id' => $division_id]);
      }
      else {
        $this->logger->error(
          "Node with id " . $node_id . " has an unknown division of '" . $division_name . "'. Skipping division update.");
      }
    }
  }

  /**
   * Returns an array of all (published and unpublished) Person nodes.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager to use to communicate with the Drupal database.
   *
   * @return array
   *   An array of all (published and unpublished) Person nodes.
   */
  private static function getPersonsNodes(EntityTypeManagerInterface $entityTypeManager) {
    $query = $entityTypeManager->getStorage('node')->getQuery();
    $query->condition('type', 'person');
    $query->accessCheck(FALSE);
    $ids = $query->execute();
    $nodes = $entityTypeManager->getStorage('node')->loadMultiple($ids);
    return $nodes;
  }

  /**
   * Returns an array of directory ids for all unpublished Persons.
   *
   * @return array
   *   An array of directory ids for all unpublished Persons.
   */
  public function getUnpublishedPersonDirectoryIds() {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->condition('type', 'person');
    $query->condition('status', FALSE);
    $query->accessCheck(FALSE);
    $ids = $query->execute();
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($ids);

    $directory_ids = [];

    foreach ($nodes as $node) {
      $directory_id = $this->getNodeValue($node, 'field_directory_id');
      if (!empty($directory_id)) {
        $directory_ids[] = $directory_id;
      }
    }
    return $directory_ids;
  }

  /**
   * Returns an associative array of Drupal node ids, keyed by directory id.
   *
   * @param array $persons
   *   An array of Person nodes from the database.
   *
   * @return array
   *   An associative array of Drupal node ids, keyed by directory id
   */
  private function getDirectoryIdsToNodeIds(array $persons) {
    $directory_ids_to_node_ids = [];

    foreach ($persons as $node) {
      $directory_id = $this->getNodeValue($node, 'field_directory_id');
      $node_id = $this->getNodeValue($node, 'nid');
      $directory_ids_to_node_ids[$directory_id] = $node_id;
    }

    return $directory_ids_to_node_ids;
  }

  /**
   * Returns an associative array of arrays of all Persons in the system.
   *
   * Array is indexed by UMD directory id. Each value array is an associative
   * array containing key/value pairs consistent with the data coming from the
   * incoming Staff Directory data.
   *
   * The array is used to "diff" against the Staff Directory associative array,
   * and so should only contain the fields present in the Staff Directory
   * associatie array.
   *
   * @return array
   *   An associative array of arrays of all (published and unpublished)
   *   Persons in the system.
   */
  public function personsToStaffDirectoryArray() {
    $nodes = $this->personNodes;

    $staff_dir_values = [];
    foreach ($nodes as $node) {
      $node_values = $this->personToStaffDirectoryArray($node);
      $directory_id = $node_values['directory_id'];
      if (empty($directory_id)) {
        $node_id = $node->get('nid')->first()->value;
        $this->logger->error(
          "Node with id " . $node_id . " does not have a directory id. Skipping.");
        continue;
      }
      $staff_dir_values[$directory_id] = $node_values;
    }
    return $staff_dir_values;
  }

  /**
   * Converts a single Person node to an associative array.
   *
   * Associative array is used for comparison to the incoming Staff Directory
   * data.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The Person node to convert.
   *
   * @return array
   *   An associative array consistent with the incoming Staff Directory data.
   */
  private function personToStaffDirectoryArray(Node $node) {
    $json_array = [];
    foreach (self::PERSON_TO_STAFF_DIRECTORY as $umdlib_field => $staff_dir_field) {
      $node_value = $this->getNodeValue($node, $umdlib_field);
      $json_array[$staff_dir_field] = $node_value;
    }
    unset($umdlib_field);
    unset($staff_dir_field);

    return $json_array;
  }

  /**
   * Returns the string value for the given field of the given Node.
   *
   * Will return an empty string ("") if the field value is null.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The Node to return the value from.
   * @param string $field
   *   The field to return the value from.
   *
   * @return string
   *   The string value for the given field of the given Node, or the
   *   empty string ("") if the field value is null.
   */
  private function getNodeValue(Node $node, string $field) {
    $node_field = $node->get($field);

    if (is_null($node_field)) {
      return "";
    }
    $node_value = $node_field->first();

    if (is_null($node_value)) {
      return "";
    }

    if (($node_value instanceof StringItem) ||
        ($node_value instanceof IntegerItem)) {
      $value = $node_value->value;
      return $value;
    }
    elseif ($node_value instanceof EntityReferenceItem) {
      $value = $node_value->get('entity')->getTarget()->getValue()->get('name')->value;
      return $value;
    }
    else {
      $this->logger->error(
        "Field " . $umdlib_field . " has an unknown node value type " . get_class($node_value));
    }
    return "";
  }

  /**
   * Returns an associative array of Division taxonomy ids by division name.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager to use to communicate with the Drupal database.
   *
   * @return array
   *   An associative array of Division taxonomy ids, indexed by Library
   *   division name.
   */
  private static function getDivisionIdsByName(EntityTypeManagerInterface $entityTypeManager) {
    $vid = "divisions";
    $terms = $entityTypeManager->getStorage('taxonomy_term')->loadTree($vid);
    $divisions = [];
    foreach ($terms as $term) {
      $divisions[$term->name] = $term->tid;
    }
    return $divisions;
  }

}
