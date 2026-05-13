<?php

namespace Drupal\umdlib_staff_directory_rest\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\umdlib_staff_directory_rest\DrupalGatewayInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Provides a UMD Staff Directory Updater REST Resource.
 *
 * @RestResource(
 *   id = "umdlib_staff_directory_rest_updater",
 *   label = @Translation("UMD Staff Directory Rest Updater"),
 *   uri_paths = {
 *     "create" = "/staff-directory/updater"
 *   }
 * )
 */
class UmdStaffDirectoryRestUpdater extends ResourceBase {

  /**
   * The DrupalGatewayInterface implementation used to communicate with Drupal.
   *
   * @var \Drupal\umdlib_staff_directory_rest\DrupalGatewayInterface
   */
  protected $gateway;

  /**
   * UmdStaffDirectoryRestUpdater constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\umdlib_staff_directory_rest\DrupalGatewayInterface $gateway
   *   The DrupalGatewayInterface implementation.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, array $serializer_formats, LoggerInterface $logger, DrupalGatewayInterface $gateway) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->gateway = $gateway;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('umdlib_staff_directory_rest'),
      $container->get('umdlib_staff_directory_rest.gateway')
    );
  }

  /**
   * Responds to POST requests.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response to the POST request.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Throws exception expected.
   */
  public function post(array $data) {
    $incoming_entries = $data;
    \Drupal::service('page_cache_kill_switch')->trigger();
    $this->logger->info(
      "Number of incoming entries: " . count($incoming_entries)
    );

    $current_entries = $this->gateway->personsToStaffDirectoryArray();
    $unpublished_directory_ids = $this->gateway->getUnpublishedPersonDirectoryIds();

    $current_directory_ids = array_keys($current_entries);
    $incoming_directory_ids = array_keys($incoming_entries);

    $directory_ids_to_add = array_diff($incoming_directory_ids, $current_directory_ids);
    $directory_ids_to_remove = self::entriesToRemove($current_directory_ids, $incoming_directory_ids, $unpublished_directory_ids);
    $directory_ids_to_update = self::entriesToUpdate($current_directory_ids, $incoming_directory_ids, $current_entries, $incoming_entries);

    $directory_ids_to_republish = self::entriesToRepublish($incoming_directory_ids, $directory_ids_to_update, $unpublished_directory_ids);

    $this->logger->info(
      "Entries to add (count=" . count($directory_ids_to_add) . "): " . implode(",", $directory_ids_to_add) . "\n" .
      "Entries to update: (count=" . count($directory_ids_to_update) . "): " . implode(",", $directory_ids_to_update) . "\n" .
      "Entries to remove: (count=" . count($directory_ids_to_remove) . "): " . implode(",", $directory_ids_to_remove) . "\n" .
      "Entries to republish: (count=" . count($directory_ids_to_republish) . "): " . implode(",", $directory_ids_to_republish) . "\n");

    $this->addEntries($directory_ids_to_add, $incoming_entries);
    $this->updateEntries($directory_ids_to_update, $incoming_entries);
    $this->removeEntries($directory_ids_to_remove);
    $this->republishEntries($directory_ids_to_republish);

    $now = (new \DateTime())->format('Y-m-d H:i:s');

    $response_message = "UmdStaffDirectoryRestUpdater::" . $now .
        "::Added: " . count($directory_ids_to_add) .
        ", Updated: " . count($directory_ids_to_update) .
        ", Removed: " . count($directory_ids_to_remove) .
        ", Republished: " . count($directory_ids_to_republish);

    $response = ['message' => $response_message];

    return new ResourceResponse($response);
  }

  /**
   * Returns an array of directory ids that should be removed.
   *
   * Entries that should be removed are those that are:
   *
   * 1) Not in the "incoming" directory ids array
   * 2) Not already unpublished (i.e., are not in the "unpublished" directory
   *    ids array.
   *
   * @param array $current_directory_ids
   *   The directory ids of all (published and unpublished) Persons.
   * @param array $incoming_directory_ids
   *   The "incoming" directory ids from the POST request.
   * @param array $unpublished_directory_ids
   *   The directory ids of "unpublished" Persons.
   */
  private static function entriesToRemove(
      array $current_directory_ids,
      array $incoming_directory_ids,
      array $unpublished_directory_ids) {
    $directory_ids_not_in_incoming = array_diff($current_directory_ids, $incoming_directory_ids);
    // Remove any ids not in the incoming list that are already unpublished.
    // This prevents entries from being unpublished every time the staff
    // directory is updated.
    $directory_ids_to_remove = array_diff($directory_ids_not_in_incoming, $unpublished_directory_ids);
    return $directory_ids_to_remove;
  }

  /**
   * Returns an array of directory ids that should be republished.
   *
   * Entries that should be republished are those that are:
   *
   * 1) Are in the "unpublished_directory_ids" directory ids array
   * 2) Are in the "incoming" directory ids array
   * 3) Are NOT in the "update" directory ids array (as they will be
   *    republished automatically).
   *
   * @param array $incoming_directory_ids
   *   The "incoming" directory ids from the POST request.
   * @param array $directory_ids_being_updated
   *   The directory ids of entries that will be updated.
   * @param array $unpublished_directory_ids
   *   The the directory ids of "unpublished" Persons.
   */
  private static function entriesToRepublish(
      array $incoming_directory_ids,
      array $directory_ids_being_updated,
      array $unpublished_directory_ids) {
    $unpublished_directory_ids_in_incoming = array_intersect($incoming_directory_ids, $unpublished_directory_ids);
    $directory_ids_to_republish = array_diff($unpublished_directory_ids_in_incoming, $directory_ids_being_updated);

    return $directory_ids_to_republish;
  }

  /**
   * Adds entries to the database for all the given directory ids.
   *
   * @param array $directory_ids_to_add
   *   The directory ids of the entries to add.
   * @param array $incoming_entries
   *   The parsed JSON file containing the incoming Staff Directory data.
   */
  private function addEntries(array $directory_ids_to_add, array $incoming_entries) {
    foreach ($directory_ids_to_add as $directory_id) {
      $staff_dir_values = $incoming_entries[$directory_id];
      $this->gateway->addEntry($staff_dir_values);
    }
  }

  /**
   * Updates database entries for the given directory ids with the given data.
   *
   * @param array $directory_ids_to_update
   *   The directory ids of the entries to add.
   * @param array $incoming_entries
   *   The parsed JSON file containing the incoming Staff Directory data.
   */
  private function updateEntries(array $directory_ids_to_update, array $incoming_entries) {
    foreach ($directory_ids_to_update as $directory_id) {
      $staff_dir_values = $incoming_entries[$directory_id];
      $this->gateway->updateEntry($directory_id, $staff_dir_values);
    }
  }

  /**
   * Removes staff directory entries by setting them to unpublished.
   *
   * @param array $directory_ids_to_remove
   *   The directory ids to remove.
   */
  private function removeEntries(array $directory_ids_to_remove) {
    foreach ($directory_ids_to_remove as $directory_id) {
      $this->gateway->removeEntry($directory_id);
    }
  }

  /**
   * Restores unpublished staff directory entries by setting them to published.
   *
   * @param array $directory_ids_to_republish
   *   The directory ids to republish.
   */
  private function republishEntries(array $directory_ids_to_republish) {
    foreach ($directory_ids_to_republish as $directory_id) {
      $this->gateway->republishEntry($directory_id);
    }
  }

  /**
   * Returns an array of directory ids that needs to be updated.
   *
   * For directory ids that are in both the $current_directory_ids and
   * $incoming_directory_ids, compares their respective entries in the
   * $current_entries and $incoming_entries arrays, adding those that are
   * different to the returned array.
   *
   * @param array $current_directory_ids
   *   The list of directory ids all Persons currently in the database.
   * @param array $incoming_directory_ids
   *   The list of directory ids in the incoming data.
   * @param array $current_entries
   *   An associative array, indexed by directory id, containing the fields
   *   values from the Person nodes.
   * @param array $incoming_entries
   *   An associative array, indexed by directory id, containing the fields
   *   values from the incoming data.
   */
  private static function entriesToUpdate(
      array $current_directory_ids,
      array $incoming_directory_ids,
      array $current_entries,
      array $incoming_entries) {
    $directory_ids_to_check = array_intersect($incoming_directory_ids, $current_directory_ids);

    $directory_ids_to_update = [];
    foreach ($directory_ids_to_check as $directory_id) {
      $current = $current_entries[$directory_id];
      $incoming = $incoming_entries[$directory_id];

      $diff = array_diff($current, $incoming);
      if (!empty($diff)) {
        array_push($directory_ids_to_update, $directory_id);
      }
    }

    return $directory_ids_to_update;
  }

}
